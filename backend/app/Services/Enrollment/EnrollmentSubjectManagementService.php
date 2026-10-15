<?php

namespace App\Services\Enrollment;

use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentSubjectManagementService
{
    public function __construct(private EnrollmentAuditWriter $audit) {}

    public static function normalizeCode(string $code): string
    {
        return Str::upper((string) preg_replace('/\s+/u', '', trim($code)));
    }

    public function save(User $user, array $input, ?int $id = null): Subject
    {
        return DB::transaction(function () use ($user, $input, $id): Subject {
            $actor = EnrollmentAccess::require($user, true, true);
            // Serialize catalog writes because the legacy schema scopes its unique index to a curriculum.
            Curriculum::query()->orderBy('id')->lockForUpdate()->pluck('id');
            $curriculum = Curriculum::with('course')->whereKey($input['curriculum_id'])->lockForUpdate()->firstOrFail();
            abort_unless($curriculum->status === 'active' && $curriculum->course?->status === 'active', 422, 'Choose an active curriculum and program.');
            abort_unless($input['year_level'] <= $curriculum->course->years, 422, 'Year level exceeds the program duration.');

            $subject = $id ? Subject::lockForUpdate()->findOrFail($id) : new Subject;
            $previousCurriculum = $subject->curriculum_id;
            $identityChanged = $id && ($subject->subject_code !== $input['subject_code']
                || $subject->curriculum_id !== $input['curriculum_id']
                || $subject->semester_id !== $input['semester_id']
                || (int) $subject->year_level !== $input['year_level']);
            if ($identityChanged) {
                abort_if($this->isReferenced($subject), 409, 'A referenced subject cannot change code, curriculum, semester, or year level.');
            }

            abort_if(Subject::query()
                ->whereRaw("UPPER(REPLACE(subject_code, ' ', '')) = ?", [$input['subject_code']])
                ->when($id, fn ($query) => $query->where('id', '!=', $id))
                ->lockForUpdate()
                ->exists(), 422, 'The Subject Code has already been used.');

            if (! empty($input['prerequisite_subject_id'])) {
                $prerequisite = Subject::lockForUpdate()->findOrFail($input['prerequisite_subject_id']);
                abort_unless($prerequisite->id !== $subject->id
                    && $prerequisite->curriculum_id === $curriculum->id
                    && $prerequisite->status === 'active', 422, 'Choose an active prerequisite from the same curriculum.');
            }

            $subject->fill($input);
            $subject->save();
            $action = $id ? ($previousCurriculum !== $subject->curriculum_id ? 'subject_curriculum_assigned' : 'subject_updated') : 'subject_created';
            $this->audit->record($actor, $action, 'subject', $subject->id, [
                'status' => $subject->status,
                'curriculum_id' => $subject->curriculum_id,
                'previous_curriculum_id' => $previousCurriculum,
            ]);

            return $subject->fresh(['curriculum.course', 'semester', 'prerequisite']);
        }, 3);
    }

    public function setStatus(User $user, int $id, string $status): Subject
    {
        return DB::transaction(function () use ($user, $id, $status): Subject {
            $actor = EnrollmentAccess::require($user, true, true);
            $subject = Subject::lockForUpdate()->findOrFail($id);
            if ($subject->status === $status) {
                return $subject->load(['curriculum.course', 'semester', 'prerequisite']);
            }
            if ($status === 'active') {
                $curriculum = Curriculum::with('course')->whereKey($subject->curriculum_id)->lockForUpdate()->firstOrFail();
                abort_unless($curriculum->status === 'active' && $curriculum->course?->status === 'active', 422, 'Reactivate the curriculum and program first.');
            }
            $subject->status = $status;
            $subject->save();
            $this->audit->record($actor, $status === 'active' ? 'subject_reactivated' : 'subject_archived', 'subject', $subject->id, [
                'status' => $subject->status,
                'curriculum_id' => $subject->curriculum_id,
                'previous_curriculum_id' => $subject->curriculum_id,
            ]);

            return $subject->fresh(['curriculum.course', 'semester', 'prerequisite']);
        }, 3);
    }

    private function isReferenced(Subject $subject): bool
    {
        return $subject->sectionSubjects()->lockForUpdate()->exists()
            || $subject->enrollmentSubjects()->lockForUpdate()->exists()
            || DB::table('enrollment_application_subjects')->where('subject_id', $subject->id)->lockForUpdate()->exists()
            || $subject->dependentSubjects()->lockForUpdate()->exists();
    }
}

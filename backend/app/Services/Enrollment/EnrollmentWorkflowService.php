<?php

namespace App\Services\Enrollment;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentDocument;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Notifications\EnrollmentNotice;
use App\Services\Admission\AdmissionHandoffService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EnrollmentWorkflowService
{
    public function __construct(
        private EnrollmentEligibilityService $eligibility,
        private EnrollmentAuditWriter $audit,
        private AdmissionHandoffService $handoff,
    ) {}

    public function period(User $user, array $input, ?int $id = null): EnrollmentPeriod
    {
        return DB::transaction(function () use ($user, $input, $id) {
            $actor = EnrollmentAccess::require($user, true, true);
            // Serialize period configuration through the stable academic-year parents,
            // including when another term has no period row yet.
            $years = AcademicYear::query()->orderBy('id')->lockForUpdate()->get();
            $year = $years->firstWhere('id', $input['academic_year_id']);
            abort_unless($year, 404);
            abort_if($input['enabled'] && $year->status !== 'active', 422, 'Only the active academic year can have an enabled enrollment period.');
            EnrollmentPeriod::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $p = $id ? EnrollmentPeriod::lockForUpdate()->findOrFail($id) : new EnrollmentPeriod;
            if ($id) {
                abort_unless($p->version === $input['version'], 409, 'This period changed. Reload before saving.');
                abort_unless($p->academic_year_id === $input['academic_year_id'] && $p->semester_id === $input['semester_id'], 422, 'A period cannot be moved to another term.');
                if (EnrollmentApplication::where('period_id', $id)->lockForUpdate()->first(['id'])) {
                    abort_unless($this->normalizedRequirements($p->document_requirements) === $this->normalizedRequirements($input['document_requirements']), 409, 'Document requirements cannot change after an application is created.');
                }
            }
            abort_if(EnrollmentPeriod::where('academic_year_id', $input['academic_year_id'])->where('semester_id', $input['semester_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first(['id']), 409, 'This term already has a period. Edit that period.');
            if ($input['enabled']) {
                abort_if(EnrollmentPeriod::where('enabled', true)
                    ->when($id, fn ($query) => $query->whereKeyNot($id))
                    ->where('opens_at', '<', $input['closes_at'])
                    ->where('closes_at', '>', $input['opens_at'])
                    ->exists(), 409, 'Another enabled enrollment period overlaps this window. Close or reschedule it first.');
            }
            $input['document_requirements'] = $this->normalizedRequirements($input['document_requirements']);
            $p->fill(collect($input)->only(['academic_year_id', 'semester_id', 'opens_at', 'closes_at', 'enabled', 'document_requirements'])->all());
            $p->updated_by = $actor->id;
            if (! $id) {
                $p->created_by = $actor->id;
            } else {
                $p->version++;
            }
            $p->save();
            $this->audit->record($actor, $id ? 'period_updated' : 'period_created', 'period', $p->id, ['version' => $p->version]);

            return $p->fresh(['academicYear', 'semester']);
        }, 3);
    }

    public function academicYear(User $user, array $input, ?int $id = null): AcademicYear
    {
        return DB::transaction(function () use ($user, $input, $id): AcademicYear {
            $actor = EnrollmentAccess::require($user, true, true);
            AcademicYear::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $year = $id ? AcademicYear::lockForUpdate()->findOrFail($id) : new AcademicYear;
            $referenced = $id && (EnrollmentPeriod::where('academic_year_id', $id)->exists()
                || DB::table('sections')->where('academic_year_id', $id)->exists()
                || DB::table('enrollments')->where('academic_year_id', $id)->exists());
            if ($referenced) {
                abort_unless($year->school_year === $input['school_year']
                    && $year->start_date?->toDateString() === $input['start_date']
                    && $year->end_date?->toDateString() === $input['end_date'], 409, 'A referenced academic year cannot change its label or dates.');
            }
            abort_if(AcademicYear::where('school_year', $input['school_year'])->when($id, fn ($query) => $query->whereKeyNot($id))->exists(), 409, 'This academic year already exists.');
            if ($input['status'] === 'active') {
                abort_if(AcademicYear::where('status', 'active')->when($id, fn ($query) => $query->whereKeyNot($id))->exists(), 409, 'Complete the current active academic year before activating another.');
            }
            if ($input['status'] === 'completed' && $id) {
                abort_if(EnrollmentPeriod::where('academic_year_id', $id)->where('enabled', true)->where('closes_at', '>', now())->exists(), 409, 'Close enabled enrollment periods before completing this academic year.');
            }
            $year->fill($input)->save();
            $this->audit->record($actor, $id ? 'academic_year_updated' : 'academic_year_created', 'academic_year', $year->id, ['status' => $year->status]);

            return $year->fresh();
        }, 3);
    }

    private function normalizedRequirements(array $requirements): array
    {
        $normalized = [];
        foreach (['regular', 'irregular', 'transferee', 'returnee'] as $classification) {
            $value = $requirements[$classification] ?? [];
            $required = array_is_list($value) ? $value : ($value['required'] ?? []);
            $optional = array_is_list($value) ? [] : ($value['optional'] ?? []);
            $required = array_values(array_unique(array_map('intval', $required)));
            $optional = array_values(array_unique(array_diff(array_map('intval', $optional), $required)));
            sort($required);
            sort($optional);
            $normalized[$classification] = ['required' => $required, 'optional' => $optional];
        }

        return $normalized;
    }

    public function create(User $user, array $input): EnrollmentApplication
    {
        return DB::transaction(function () use ($user, $input) {
            $actor = EnrollmentAccess::require($user, false, true);
            $p = EnrollmentPeriod::lockForUpdate()->findOrFail($input['period_id']);
            $student = Student::where('user_id', $actor->id)->lockForUpdate()->first();
            $admission = null;
            if ($actor->role?->role_name === Role::GUEST) {
                $admission = $this->eligibleGuest($actor, $p);
                $courseId = $admission['course']->id;
                $curriculumId = $admission['curriculum']->id;
                $yearLevel = 1;
            } else {
                abort_unless($student, 422, 'The Student academic identity needs review.');
                $this->eligibleStudent($actor, $student, $p);
                $courseId = $student->course_id;
                $curriculumId = $student->curriculum_id;
                $yearLevel = $student->year_level;
            }
            $existing = EnrollmentApplication::ownedBy($actor)->where('academic_year_id', $p->academic_year_id)
                ->where('semester_id', $p->semester_id)->lockForUpdate()->first();
            abort_if($existing, 409, 'An application already exists for this term. Open your existing application.');
            if ($admission) {
                $existingHandoff = EnrollmentApplication::where('admission_applicant_id', $admission['applicant']->id)
                    ->lockForUpdate()->first();
                abort_if($existingHandoff, 409, 'This Admission handoff already has an Enrollment application. Open your existing application.');
            }
            $a = EnrollmentApplication::create([
                'student_id' => $student?->id, 'owner_user_id' => $actor->id,
                'admission_applicant_id' => $admission['applicant']->id ?? null,
                'period_id' => $p->id, 'academic_year_id' => $p->academic_year_id,
                'semester_id' => $p->semester_id, 'course_id' => $courseId,
                'curriculum_id' => $curriculumId, 'year_level' => $yearLevel,
                'classification' => $input['classification'], 'status' => 'draft',
            ]);
            $this->audit->record($actor, 'draft_created', 'application', $a->id, ['status' => $a->status, 'version' => 1]);

            return $a->fresh();
        }, 3);
    }

    private function eligibleStudent(User $actor, Student $student, EnrollmentPeriod $p): void
    {
        // Hold academic configuration stable through submission.
        Course::whereKey($student->course_id)->lockForUpdate()->first();
        Curriculum::whereKey($student->curriculum_id)->lockForUpdate()->first();
        AcademicYear::whereKey($p->academic_year_id)->lockForUpdate()->first();
        Semester::whereKey($p->semester_id)->lockForUpdate()->first();
        $state = $this->eligibility->status($actor, true);
        abort_unless($p->state === 'open' && $state['eligible'] && ($state['period']['id'] ?? null) === $p->id, 409, 'Enrollment is not currently available. Reload your eligibility.');
    }

    private function eligibleGuest(User $actor, EnrollmentPeriod $period): array
    {
        $admission = $this->handoff->context($actor, true);
        abort_unless($admission['admitted_guest'], 409, 'The Admission handoff is no longer eligible for Enrollment.');
        $cycle = $admission['applicant']->cycle()->lockForUpdate()->first();
        abort_unless($cycle && $cycle->academic_year_id === $period->academic_year_id && $period->state === 'open',
            409, 'Enrollment is not currently available for the admitted academic year.');
        Course::whereKey($admission['course']->id)->lockForUpdate()->firstOrFail();
        Curriculum::whereKey($admission['curriculum']->id)->lockForUpdate()->firstOrFail();

        return $admission;
    }

    public function mutate(User $user, int $id, string $action, array $input): EnrollmentApplication
    {
        return DB::transaction(function () use ($user, $id, $action, $input) {
            $staff = in_array($action, ['review', 'approve', 'reject'], true);
            $actor = EnrollmentAccess::require($user, $staff, true);
            $ref = EnrollmentApplication::findOrFail($id);
            abort_unless($staff || $ref->owner_user_id === $actor->id, 404, 'Application not found.');
            $owner = User::with('role')->whereKey($ref->owner_user_id)->lockForUpdate()->firstOrFail();
            $student = $ref->student_id ? Student::lockForUpdate()->findOrFail($ref->student_id) : null;
            abort_unless($staff || $owner->id === $actor->id, 404, 'Application not found.');
            $p = EnrollmentPeriod::lockForUpdate()->findOrFail($ref->period_id);
            $a = EnrollmentApplication::lockForUpdate()->findOrFail($id);
            if ($action === 'submit' && $a->status === 'submitted' && $a->version === $input['version'] + 1) {
                return $a;
            }
            abort_unless($a->version === $input['version'], 409, 'This application changed. Reload before continuing.');
            $from = ['save' => ['draft'], 'submit' => ['draft'], 'cancel' => ['draft'], 'review' => ['submitted'], 'approve' => ['under_review'], 'reject' => ['submitted', 'under_review']];
            abort_unless(in_array($a->status, $from[$action] ?? [], true), 409, 'This action is not allowed for the current application status.');
            if ($action === 'save') {
                $a->classification = $input['classification'];
            }
            if ($action === 'submit') {
                if ($student) {
                    $this->eligibleStudent($owner, $student, $p);
                    $a->course_id = $student->course_id;
                    $a->curriculum_id = $student->curriculum_id;
                    $a->year_level = $student->year_level;
                } else {
                    $admission = $this->eligibleGuest($owner, $p);
                    abort_unless($a->admission_applicant_id === $admission['applicant']->id
                        && $a->course_id === $admission['course']->id
                        && $a->curriculum_id === $admission['curriculum']->id,
                        409, 'The Admission handoff changed. Reload before submitting.');
                }
                $this->documentsComplete($a, $p);
                $a->submitted_at = now();
            }
            if ($staff) {
                // Approval is an application decision only, never an academic enrollment.
                if ($action === 'approve') {
                    $this->documentsComplete($a, $p);
                }
                $a->reviewed_by = $actor->id;
                $a->reviewed_at = now();
                $a->review_notes = $input['notes'] ?? null;
            }
            $a->status = ['save' => 'draft', 'submit' => 'submitted', 'cancel' => 'cancelled', 'review' => 'under_review', 'approve' => 'approved', 'reject' => 'rejected'][$action];
            $a->version++;
            $a->save();
            if ($action === 'reject') {
                $owner->notify(new EnrollmentNotice('rejected', 'Your enrollment application was rejected. Review the staff notes.', $a->id));
            }
            $event = ['save' => 'draft_saved', 'submit' => 'submitted', 'cancel' => 'cancelled', 'review' => 'review_started', 'approve' => 'approved', 'reject' => 'rejected'][$action];
            $this->audit->record($actor, $event, 'application', $a->id, ['status' => $a->status, 'version' => $a->version]);

            return $a;
        }, 3);
    }

    public function requirements(EnrollmentApplication $a): array
    {
        $configuration = $a->period->documentConfiguration($a->classification);
        $ids = $a->period->documentTypeIds($a->classification);
        $attached = $a->documents->keyBy('document_type_id');
        $existing = $a->student_id
            ? StudentDocument::where('student_id', $a->student_id)->whereIn('document_type_id', $ids)->where('verification_status', 'verified')->get()->keyBy('document_type_id')
            : collect();

        return DocumentType::whereIn('id', $ids)->orderBy('document_name')->get()->map(function ($type) use ($attached, $existing, $configuration) {
            $doc = $attached->get($type->id);
            $reuse = $existing->get($type->id);
            $reusable = $reuse && $reuse->file_path && Storage::disk('local')->exists($reuse->file_path);

            return ['id' => $type->id, 'name' => $type->document_name, 'required' => in_array($type->id, $configuration['required'], true), 'attached' => $doc !== null, 'document_id' => $doc?->id, 'reusable' => $reusable, 'student_document_id' => $reusable ? $reuse->id : null];
        })->all();
    }

    private function documentsComplete(EnrollmentApplication $a, EnrollmentPeriod $p): void
    {
        foreach ($p->documentConfiguration($a->classification)['required'] as $type) {
            $doc = $a->documents()->where('document_type_id', $type)->lockForUpdate()->first();
            // Reuse verified evidence automatically; no repeat Student upload.
            if (! $doc) {
                $existing = $a->student_id ? StudentDocument::where('student_id', $a->student_id)->where('document_type_id', $type)->where('verification_status', 'verified')->lockForUpdate()->first() : null;
                if ($existing && $existing->file_path && Storage::disk('local')->exists($existing->file_path)) {
                    $doc = $a->documents()->create(['document_type_id' => $type, 'student_document_id' => $existing->id]);
                }
            }
            $path = $this->documentPath($a, $doc, true);
            abort_unless($path && Storage::disk('local')->exists($path), 422, 'Required documents are missing or no longer available.');
        }
    }

    public function documentPath(EnrollmentApplication $a, ?EnrollmentDocument $doc, bool $lock = false): ?string
    {
        if (! $doc) {
            return null;
        }
        if ($doc->student_document_id) {
            return StudentDocument::whereKey($doc->student_document_id)->where('student_id', $a->student_id)->where('document_type_id', $doc->document_type_id)->where('verification_status', 'verified')->when($lock, fn ($q) => $q->lockForUpdate())->value('file_path');
        }

        return $doc->file_path;
    }

    public function upload(User $user, int $id, array $input, UploadedFile $file): EnrollmentDocument
    {
        $path = null;
        try {
            return DB::transaction(function () use ($user, $id, $input, $file, &$path) {
                $actor = EnrollmentAccess::require($user, false, true);
                $ref = EnrollmentApplication::findOrFail($id);
                abort_unless($ref->owner_user_id === $actor->id, 404, 'Application not found.');
                $student = $ref->student_id ? Student::whereKey($ref->student_id)->lockForUpdate()->firstOrFail() : null;
                $a = EnrollmentApplication::lockForUpdate()->findOrFail($id);
                abort_unless($a->status === 'draft' && $a->version === $input['version'], 409, 'Reload your draft before uploading.');
                abort_unless(in_array($input['document_type_id'], $a->period->documentTypeIds($a->classification), true), 422, 'This document is not configured for your classification.');
                abort_if($a->documents()->where('document_type_id', $input['document_type_id'])->lockForUpdate()->first(['id']), 409, 'This requirement already has a document.');
                $existing = $student ? StudentDocument::where('student_id', $student->id)->where('document_type_id', $input['document_type_id'])->where('verification_status', 'verified')->lockForUpdate()->first() : null;
                abort_if($existing && $existing->file_path && Storage::disk('local')->exists($existing->file_path), 409, 'A verified document is already available and will be reused when submitting.');
                $path = $file->store('enrollment/'.$a->id, 'local');
                abort_unless($path, 503, 'The document could not be stored.');
                $doc = $a->documents()->create(['document_type_id' => $input['document_type_id'], 'file_path' => $path]);
                $a->version++;
                $a->save();
                $this->audit->record($actor, 'document_attached', 'application', $a->id, ['version' => $a->version]);

                return $doc;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
    }
}

<?php

namespace App\Services\Grading;

use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeAuditEvent;
use App\Models\GradeCategoryWeight;
use App\Models\GradePeriodSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GradingService
{
    public const CATEGORIES = ['Quiz', 'Activity', 'Recitation', 'Major Exam'];

    public const DEFAULT_WEIGHTS = ['Quiz' => 15, 'Activity' => 35, 'Recitation' => 10, 'Major Exam' => 40];

    public function professor(User $user): Professor
    {
        $actor = $user->fresh('role');
        abort_unless($actor->status === 'active' && $actor->role?->role_name === Role::PROFESSOR, 403, 'Active Professor access required.');

        $professor = Professor::where('user_id', $actor->id)->where('status', 'active')->first();
        abort_unless($professor, 409, 'Your academic Professor profile is not configured. Contact the Registrar before using Grading.');

        return $professor;
    }

    public function classes(User $user, array $filters)
    {
        $professor = $this->professor($user);
        $query = SectionSubject::query()->where('professor_id', $professor->id)
            ->with(['section.course.department', 'section.academicYear', 'section.semester', 'subject', 'gradeSheet'])
            ->select('section_subjects.*')->selectSub(function ($query) {
                $query->from('enrollment_subjects as es')->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
                    ->whereColumn('e.section_id', 'section_subjects.section_id')
                    ->whereColumn('es.subject_id', 'section_subjects.subject_id')
                    ->whereIn('e.status', ['enrolled', 'completed'])->whereIn('es.subject_status', ['enrolled', 'completed'])
                    ->selectRaw('count(*)');
            }, 'student_count');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->whereHas('subject', fn ($s) => $s->where('subject_code', 'like', "%{$search}%")->orWhere('subject_name', 'like', "%{$search}%"))->orWhereHas('section', fn ($s) => $s->where('section_name', 'like', "%{$search}%")));
        }
        foreach (['academic_year_id', 'semester_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->whereHas('section', fn ($q) => $q->where($key, $filters[$key]));
            }
        }

        $page = $query->latest('section_subjects.id')->paginate(24);
        $terms = $page->getCollection()->map(fn ($assignment) => [$assignment->section->academic_year_id, $assignment->section->semester_id])->unique();
        if ($terms->isEmpty()) {
            return $page;
        }
        $schedules = GradePeriodSchedule::where(function ($query) use ($terms) {
            foreach ($terms as [$year, $semester]) {
                $query->orWhere(fn ($term) => $term->where('academic_year_id', $year)->where('semester_id', $semester));
            }
        })->get()->keyBy(fn ($schedule) => $schedule->academic_year_id.':'.$schedule->semester_id);
        $page->getCollection()->transform(fn ($assignment) => $this->classData($assignment, null, $schedules->get($assignment->section->academic_year_id.':'.$assignment->section->semester_id)));

        return $page;
    }

    public function openWorkspace(User $user, SectionSubject $assignment): array
    {
        $professor = $this->professor($user);
        abort_unless($assignment->professor_id === $professor->id, 404, 'Assigned class not found.');
        $sheet = DB::transaction(function () use ($user, $assignment, $professor) {
            $locked = SectionSubject::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($locked->professor_id === $professor->id, 409, 'The class assignment changed. Reload before continuing.');
            $sheet = GradeSheet::firstOrCreate(['section_subject_id' => $locked->id], ['professor_id' => $professor->id]);
            abort_unless($sheet->professor_id === $professor->id, 409, 'The grade sheet belongs to another Professor assignment.');
            if ($sheet->wasRecentlyCreated) {
                foreach (['midterm', 'finals'] as $period) {
                    foreach (self::DEFAULT_WEIGHTS as $category => $weight) {
                        GradeCategoryWeight::create(['grade_sheet_id' => $sheet->id, 'period' => $period, 'category' => $category, 'weight_percentage' => $weight]);
                    }
                }
                $this->audit($user, 'grade_sheet.created', $sheet, null, ['section_subject_id' => $locked->id]);
            }

            return $sheet;
        });

        return $this->workspaceData($assignment->fresh(), $sheet->fresh());
    }

    public function saveAssessment(User $user, GradeSheet $sheet, array $input, ?GradeAssessment $assessment = null): GradeAssessment
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $input['period'] ?? $assessment?->period);

        return DB::transaction(function () use ($user, $sheet, $input, $assessment) {
            if ($assessment) {
                $row = GradeAssessment::lockForUpdate()->where('grade_sheet_id', $sheet->id)->findOrFail($assessment->id);
                abort_unless($row->version === (int) $input['version'], 409, 'This assessment changed. Reload before continuing.');
                $old = $row->only(['label', 'category', 'max_score', 'display_order', 'status']);
                $row->fill(collect($input)->only(['label', 'category', 'max_score', 'display_order'])->all());
                $row->version++;
                $row->save();
                $this->audit($user, 'assessment.updated', $sheet, null, ['assessment_id' => $row->id, 'old' => $old, 'new' => $row->only(array_keys($old))]);

                return $row;
            }
            $row = GradeAssessment::create($input + ['grade_sheet_id' => $sheet->id]);
            $this->audit($user, 'assessment.created', $sheet, null, ['assessment_id' => $row->id, 'new' => $row->only(['period', 'label', 'category', 'max_score', 'display_order'])]);

            return $row;
        });
    }

    public function assessmentStatus(User $user, GradeSheet $sheet, GradeAssessment $assessment, array $input): GradeAssessment
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $assessment->period);

        return DB::transaction(function () use ($user, $sheet, $assessment, $input) {
            $row = GradeAssessment::lockForUpdate()->where('grade_sheet_id', $sheet->id)->findOrFail($assessment->id);
            abort_unless($row->version === (int) $input['version'], 409, 'This assessment changed. Reload before continuing.');
            $row->status = $input['status'];
            $row->version++;
            $row->save();
            $this->audit($user, $row->status === 'archived' ? 'assessment.archived' : 'assessment.restored', $sheet, null, ['assessment_id' => $row->id]);

            return $row;
        });
    }

    public function saveScores(User $user, GradeSheet $sheet, array $input): array
    {
        $this->ownedSheet($user, $sheet);
        $assessment = GradeAssessment::where('grade_sheet_id', $sheet->id)->where('status', 'active')->findOrFail($input['assessment_id']);
        $this->assertOpen($sheet, $assessment->period);

        return DB::transaction(function () use ($user, $sheet, $assessment, $input) {
            $saved = [];
            foreach ($input['scores'] as $entry) {
                abort_unless((float) $entry['score'] <= (float) $assessment->max_score, 422, 'A score exceeds the assessment maximum.');
                $valid = $this->rosterQuery($sheet)->where('es.id', $entry['enrollment_subject_id'])->exists();
                abort_unless($valid, 422, 'A Student is not enrolled in this class and subject.');
                $score = GradeScore::lockForUpdate()->where('grade_assessment_id', $assessment->id)->where('enrollment_subject_id', $entry['enrollment_subject_id'])->first();
                if ($score) {
                    abort_unless(isset($entry['version']) && $score->version === (int) $entry['version'], 409, 'A score changed in another session. Reload before saving.');
                    $old = $score->score;
                    $score->score = $entry['score'];
                    $score->updated_by = $user->id;
                    $score->version++;
                    $score->save();
                    $action = 'score.updated';
                } else {
                    $old = null;
                    $score = GradeScore::create(['grade_assessment_id' => $assessment->id, 'enrollment_subject_id' => $entry['enrollment_subject_id'], 'score' => $entry['score'], 'updated_by' => $user->id]);
                    $action = 'score.created';
                }
                $this->audit($user, $action, $sheet, null, ['assessment_id' => $assessment->id, 'enrollment_subject_id' => $score->enrollment_subject_id, 'old' => $old, 'new' => $score->score]);
                $saved[] = $score;
            }

            return $saved;
        });
    }

    public function updateWeights(User $user, GradeSheet $sheet, string $period, array $weights): array
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $period);
        abort_unless(abs(array_sum(array_column($weights, 'weight_percentage')) - 100) < 0.001, 422, 'Category weights must total 100%.');

        return DB::transaction(function () use ($user, $sheet, $period, $weights) {
            foreach ($weights as $input) {
                $row = GradeCategoryWeight::lockForUpdate()->where(['grade_sheet_id' => $sheet->id, 'period' => $period, 'category' => $input['category']])->firstOrFail();
                abort_unless($row->version === (int) $input['version'], 409, 'Category weights changed. Reload before continuing.');
                $row->update(['weight_percentage' => $input['weight_percentage'], 'version' => $row->version + 1]);
            }
            $this->audit($user, 'grade_weights.updated', $sheet, null, ['period' => $period]);

            return $sheet->weights()->where('period', $period)->orderBy('id')->get()->all();
        });
    }

    public function periodState(GradePeriodSchedule $schedule, string $period, ?Carbon $now = null): array
    {
        $now ??= now();
        $open = $schedule->{$period.'_opens_at'};
        $deadline = $schedule->{$period.'_deadline'};
        $state = $schedule->status === 'archived' || $now->greaterThanOrEqualTo($deadline) ? 'Closed' : ($now->lessThan($open) ? 'Upcoming' : 'Open');

        return ['state' => $state, 'editable' => $state === 'Open', 'opens_at' => $open?->toIso8601String(), 'deadline' => $deadline?->toIso8601String()];
    }

    public function scheduleData(?GradePeriodSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        return $schedule->toArray() + ['midterm_state' => $this->periodState($schedule, 'midterm'), 'finals_state' => $this->periodState($schedule, 'finals')];
    }

    private function ownedSheet(User $user, GradeSheet $sheet): void
    {
        $professor = $this->professor($user);
        abort_unless($sheet->professor_id === $professor->id && $sheet->sectionSubject()->where('professor_id', $professor->id)->exists(), 404, 'Grade sheet not found.');
        abort_unless(in_array($sheet->status, ['draft', 'returned'], true), 409, 'This grade sheet is locked for review or publication.');
    }

    private function assertOpen(GradeSheet $sheet, ?string $period): void
    {
        abort_unless(in_array($period, ['midterm', 'finals'], true), 422, 'A valid grading period is required.');
        if ($sheet->status === 'returned') {
            return;
        }
        $section = $sheet->sectionSubject()->with('section')->firstOrFail()->section;
        $schedule = GradePeriodSchedule::where(['academic_year_id' => $section->academic_year_id, 'semester_id' => $section->semester_id, 'status' => 'active'])->first();
        abort_unless($schedule, 409, 'No active grading schedule is configured for this term.');
        $state = $this->periodState($schedule, $period);
        abort_unless($state['editable'], 409, ucfirst($period).' grade entry is '.$state['state'].'.');
    }

    private function rosterQuery(GradeSheet $sheet): Builder
    {
        $assignment = $sheet->sectionSubject;

        return EnrollmentSubject::query()->from('enrollment_subjects as es')
            ->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
            ->where('e.section_id', $assignment->section_id)->where('es.subject_id', $assignment->subject_id)
            ->whereIn('e.status', ['enrolled', 'completed'])->whereIn('es.subject_status', ['enrolled', 'completed']);
    }

    private function workspaceData(SectionSubject $assignment, GradeSheet $sheet): array
    {
        $assignment->load(['section.course.department', 'section.academicYear', 'section.semester', 'subject', 'professor.user.profile']);
        $roster = $this->rosterQuery($sheet)->join('students as s', 's.id', '=', 'e.student_id')->join('user_profiles as up', 'up.id', '=', 's.user_profile_id')
            ->orderBy('up.last_name')->orderBy('up.first_name')->get(['es.id as enrollment_subject_id', 's.id as student_id', 's.student_number', 'up.first_name', 'up.middle_name', 'up.last_name']);
        $sheet->load(['assessments.scores', 'weights']);
        $schedule = GradePeriodSchedule::where(['academic_year_id' => $assignment->section->academic_year_id, 'semester_id' => $assignment->section->semester_id])->first();

        return ['class' => $this->classData($assignment, $roster->count(), $schedule), 'sheet' => $sheet, 'roster' => $roster, 'schedule' => $this->scheduleData($schedule), 'categories' => self::CATEGORIES];
    }

    private function classData(SectionSubject $assignment, ?int $count = null, ?GradePeriodSchedule $schedule = null): array
    {
        $section = $assignment->section;

        return ['id' => $assignment->id, 'subject_code' => $assignment->subject->subject_code, 'subject_name' => $assignment->subject->subject_name, 'units' => (float) $assignment->subject->units, 'section' => $section->section_name, 'year_level' => $section->year_level, 'program' => $section->course->course_code ?? $section->course->course_name, 'institute' => $section->course->department?->department_name, 'academic_year' => $section->academicYear->school_year, 'semester' => $section->semester->semester_name, 'student_count' => $count ?? (int) $assignment->student_count, 'sheet_id' => $assignment->gradeSheet?->id, 'schedule' => $this->scheduleData($schedule)];
    }

    public function audit(User $actor, string $action, ?GradeSheet $sheet, ?GradePeriodSchedule $schedule, array $metadata = []): void
    {
        GradeAuditEvent::create(['actor_user_id' => $actor->id, 'grade_sheet_id' => $sheet?->id, 'grade_period_schedule_id' => $schedule?->id, 'action' => $action, 'metadata' => $metadata]);
    }
}

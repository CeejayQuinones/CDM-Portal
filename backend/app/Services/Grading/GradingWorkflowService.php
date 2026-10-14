<?php

namespace App\Services\Grading;

use App\Models\EnrollmentSubject;
use App\Models\GradePeriodSchedule;
use App\Models\GradeReleaseItem;
use App\Models\GradeReleaseSchedule;
use App\Models\GradeSheet;
use App\Models\GradeSubmissionAttempt;
use App\Models\GradeSubmissionStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GradingWorkflowService
{
    public function __construct(private GradingService $grading) {}

    public function readiness(User $actor, GradeSheet $sheet): array
    {
        $this->grading->professor($actor);
        abort_unless($sheet->professor()->where('user_id', $actor->id)->exists(), 404, 'Grade sheet not found.');

        return $this->compute($sheet);
    }

    public function compute(GradeSheet $sheet): array
    {
        $sheet->load([
            'sectionSubject.subject',
            'sectionSubject.section.academicYear',
            'sectionSubject.section.semester',
            'sectionSubject.section.course',
            'professor.user.profile',
            'assessments' => fn ($q) => $q->where('status', 'active')->orderBy('period')->orderBy('display_order'),
            'assessments.scores',
            'weights',
        ]);
        $errors = [];
        $finalWeights = ['midterm' => $this->scaled($sheet->midterm_weight), 'finals' => $this->scaled($sheet->finals_weight)];
        if (array_sum($finalWeights) !== 10000) {
            $errors[] = 'Midterm and Finals weights must total 100%.';
        }

        $weights = [];
        foreach (['midterm', 'finals'] as $period) {
            $rows = $sheet->weights->where('period', $period)->sortBy('category');
            if ($rows->count() !== count(GradingService::CATEGORIES) || $rows->sum(fn ($row) => $this->scaled($row->weight_percentage)) !== 10000) {
                $errors[] = ucfirst($period).' category weights must contain all categories and total 100%.';
            }
            $weights[$period] = $rows->mapWithKeys(fn ($row) => [$row->category => $this->scaled($row->weight_percentage)])->all();
            $periodAssessments = $sheet->assessments->where('period', $period);
            if ($periodAssessments->isEmpty()) {
                $errors[] = ucfirst($period).' requires active assessments.';
            }
            foreach ($weights[$period] as $category => $weight) {
                if ($weight > 0 && $periodAssessments->where('category', $category)->isEmpty()) {
                    $errors[] = ucfirst($period).' requires an active '.$category.' assessment.';
                }
            }
        }

        $roster = $this->roster($sheet)->get();
        if ($roster->isEmpty()) {
            $errors[] = 'The official class roster is empty.';
        }
        $results = [];
        foreach ($roster as $student) {
            $periodGrades = [];
            $breakdown = [];
            foreach (['midterm', 'finals'] as $period) {
                $periodBasis = 0;
                foreach ($weights[$period] ?? [] as $category => $weightBasis) {
                    $assessments = $sheet->assessments->where('period', $period)->where('category', $category);
                    $earned = 0;
                    $possible = 0;
                    foreach ($assessments as $assessment) {
                        $possible += $this->scaled($assessment->max_score);
                        $score = $assessment->scores->firstWhere('enrollment_subject_id', $student->enrollment_subject_id);
                        if (! $score) {
                            $errors[] = $student->student_number.' is missing '.$assessment->label.'.';

                            continue;
                        }
                        $scoreValue = $this->scaled($score->score);
                        if ($scoreValue < 0 || $scoreValue > $this->scaled($assessment->max_score)) {
                            $errors[] = $student->student_number.' has an invalid score for '.$assessment->label.'.';
                        }
                        $earned += $scoreValue;
                    }
                    $weightedBasis = $possible > 0 ? (int) round(($earned * $weightBasis) / $possible, 0, PHP_ROUND_HALF_UP) : 0;
                    $periodBasis += $weightedBasis;
                    $breakdown[$period][$category] = ['earned' => $earned / 100, 'possible' => $possible / 100, 'weight' => $weightBasis / 100, 'weighted_grade' => $weightedBasis / 100];
                }
                $periodGrades[$period] = $periodBasis;
            }
            $finalBasis = (int) round((($periodGrades['midterm'] ?? 0) * $finalWeights['midterm'] + ($periodGrades['finals'] ?? 0) * $finalWeights['finals']) / 10000, 0, PHP_ROUND_HALF_UP);
            $results[] = ['enrollment_subject_id' => $student->enrollment_subject_id, 'student_id' => $student->student_id, 'student_number' => $student->student_number, 'student_name' => trim($student->last_name.', '.$student->first_name.' '.($student->middle_name ?? '')), 'midterm_grade' => ($periodGrades['midterm'] ?? 0) / 100, 'finals_grade' => ($periodGrades['finals'] ?? 0) / 100, 'final_grade' => $finalBasis / 100, 'grade_point' => null, 'remarks' => null, 'breakdown' => $breakdown];
        }
        $errors = array_values(array_unique($errors));
        $configuration = ['rounding' => 'integer hundredths, half up', 'grade_scale_configured' => false, 'midterm_weight' => $finalWeights['midterm'] / 100, 'finals_weight' => $finalWeights['finals'] / 100, 'category_weights' => collect($weights)->map(fn ($period) => collect($period)->map(fn ($value) => $value / 100)->all())->all(), 'assessments' => $sheet->assessments->map(fn ($row) => ['id' => $row->id, 'period' => $row->period, 'label' => $row->label, 'category' => $row->category, 'max_score' => (float) $row->max_score, 'version' => $row->version])->values()->all(), 'context' => $this->contextSnapshot($sheet)];
        $checksum = $this->checksum($configuration, $results);

        return ['ready' => $errors === [], 'errors' => $errors, 'results' => $errors === [] ? $results : [], 'configuration' => $configuration, 'checksum' => $checksum];
    }

    public function submit(User $actor, GradeSheet $sheet, int $version): GradeSheet
    {
        $professor = $this->grading->professor($actor);

        return DB::transaction(function () use ($actor, $sheet, $version, $professor) {
            $locked = GradeSheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless($locked->professor_id === $professor->id, 404, 'Grade sheet not found.');
            abort_unless(in_array($locked->status, ['draft', 'returned'], true), 409, 'Only Draft or Returned grade sheets can be submitted.');
            abort_unless($locked->version === $version, 409, 'This grade sheet changed. Reload before submitting.');
            $section = $locked->sectionSubject()->with('section')->firstOrFail()->section;
            $schedule = GradePeriodSchedule::where(['academic_year_id' => $section->academic_year_id, 'semester_id' => $section->semester_id, 'status' => 'active'])->first();
            abort_unless($schedule && now()->greaterThanOrEqualTo($schedule->midterm_deadline) && now()->greaterThanOrEqualTo($schedule->finals_deadline), 409, 'Both grading windows must be complete before submission.');
            $computed = $this->compute($locked);
            abort_unless($computed['ready'], 422, implode(' ', $computed['errors']));
            $attemptNumber = (int) GradeSubmissionAttempt::where('grade_sheet_id', $locked->id)->lockForUpdate()->max('attempt_number') + 1;
            $attempt = GradeSubmissionAttempt::create(['grade_sheet_id' => $locked->id, 'attempt_number' => $attemptNumber, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'sheet_version' => $locked->version, 'midterm_weight' => $locked->midterm_weight, 'finals_weight' => $locked->finals_weight, 'configuration' => $computed['configuration'], 'checksum' => $computed['checksum']]);
            foreach ($computed['results'] as $result) {
                GradeSubmissionStudent::create($result + ['grade_submission_attempt_id' => $attempt->id]);
            }
            $oldStatus = $locked->status;
            $locked->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'reviewed_at' => null, 'reviewed_by' => null, 'returned_at' => null, 'returned_by' => null, 'return_reason' => null, 'version' => $locked->version + 1])->save();
            $this->grading->audit($actor, $attemptNumber > 1 ? 'grade_sheet.resubmitted' : 'grade_sheet.submitted', $locked, null, ['old_status' => $oldStatus, 'new_status' => 'submitted', 'attempt_number' => $attemptNumber, 'version' => $locked->version]);

            return $locked->fresh(['latestSubmission.students']);
        });
    }

    public function updateFinalWeights(User $actor, GradeSheet $sheet, array $input): GradeSheet
    {
        $professor = $this->grading->professor($actor);
        abort_unless(abs(((float) $input['midterm_weight'] + (float) $input['finals_weight']) - 100) < 0.001, 422, 'Midterm and Finals weights must total 100%.');

        return DB::transaction(function () use ($actor, $sheet, $input, $professor) {
            $locked = GradeSheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless($locked->professor_id === $professor->id, 404, 'Grade sheet not found.');
            abort_unless(in_array($locked->status, ['draft', 'returned'], true), 409, 'This grade sheet is locked for review or publication.');
            abort_unless($locked->version === (int) $input['version'], 409, 'This grade sheet changed. Reload before saving weights.');
            $old = ['midterm_weight' => $locked->midterm_weight, 'finals_weight' => $locked->finals_weight];
            $locked->forceFill(['midterm_weight' => $input['midterm_weight'], 'finals_weight' => $input['finals_weight'], 'version' => $locked->version + 1])->save();
            $this->grading->audit($actor, 'grade_final_weights.updated', $locked, null, ['old' => $old, 'new' => ['midterm_weight' => $locked->midterm_weight, 'finals_weight' => $locked->finals_weight], 'version' => $locked->version]);

            return $locked;
        });
    }

    public function reviewList(array $filters)
    {
        $query = GradeSheet::with(['sectionSubject.subject', 'sectionSubject.section.course', 'sectionSubject.section.academicYear', 'sectionSubject.section.semester', 'professor.user.profile', 'latestSubmission' => fn ($q) => $q->withCount('students')]);
        if (! empty($filters['professor_id'])) {
            $query->where('professor_id', $filters['professor_id']);
        }
        if (! empty($filters['subject_id'])) {
            $query->whereHas('sectionSubject', fn ($q) => $q->where('subject_id', $filters['subject_id']));
        }
        $query->whereHas('sectionSubject.section', function ($q) use ($filters) {
            foreach (['academic_year_id', 'semester_id', 'course_id', 'year_level', 'section_id'] as $key) {
                if (! empty($filters[$key])) {
                    $q->where($key === 'section_id' ? 'id' : $key, $filters[$key]);
                }
            }
        });
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->whereHas('sectionSubject.subject', fn ($s) => $s->where('subject_code', 'like', $search)->orWhere('subject_name', 'like', $search))->orWhereHas('sectionSubject.section', fn ($s) => $s->where('section_name', 'like', $search))->orWhereHas('professor.user.profile', fn ($p) => $p->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)));
        }

        $summary = [
            'pending_review' => (clone $query)->where('status', 'submitted')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'published' => (clone $query)->where('status', 'published')->count(),
        ];
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return ['summary' => $summary, 'sheets' => $query->whereIn('status', ['submitted', 'approved', 'published'])->latest('submitted_at')->paginate(25)];
    }

    public function reviewDetail(GradeSheet $sheet): GradeSheet
    {
        abort_unless(in_array($sheet->status, ['submitted', 'approved', 'published'], true), 404, 'Reviewable grade sheet not found.');

        return $sheet->load(['sectionSubject.subject', 'sectionSubject.section.course', 'sectionSubject.section.academicYear', 'sectionSubject.section.semester', 'professor.user.profile', 'latestSubmission.students', 'submissions:id,grade_sheet_id,attempt_number,submitted_at,submitted_by,checksum']);
    }

    public function review(User $actor, GradeSheet $sheet, string $action, int $version, ?string $reason = null): GradeSheet
    {
        return DB::transaction(function () use ($actor, $sheet, $action, $version, $reason) {
            $locked = GradeSheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless($locked->status === 'submitted', 409, 'Only Submitted grade sheets can be reviewed.');
            abort_unless($locked->version === $version, 409, 'This grade sheet changed. Reload before reviewing.');
            $this->assertSnapshotValid($locked);
            if ($action === 'return') {
                abort_unless(trim((string) $reason) !== '', 422, 'A return reason is required.');
                $locked->forceFill(['status' => 'returned', 'returned_at' => now(), 'returned_by' => $actor->id, 'return_reason' => trim($reason), 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'version' => $locked->version + 1])->save();
                $event = 'grade_sheet.returned';
            } else {
                abort_unless($action === 'approve', 404);
                $locked->forceFill(['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'version' => $locked->version + 1])->save();
                $event = 'grade_sheet.approved';
            }
            $this->grading->audit($actor, $event, $locked, null, ['old_status' => 'submitted', 'new_status' => $locked->status, 'reason' => $reason, 'version' => $locked->version]);

            return $locked->fresh(['latestSubmission.students']);
        });
    }

    public function releaseList(): array
    {
        return ['approved_sheets' => GradeSheet::where('status', 'approved')->with(['sectionSubject.subject', 'sectionSubject.section.academicYear', 'sectionSubject.section.semester', 'sectionSubject.section.course', 'professor.user.profile', 'latestSubmission'])->latest('reviewed_at')->get(), 'schedules' => GradeReleaseSchedule::with(['academicYear', 'semester', 'items.gradeSheet.sectionSubject.subject', 'items.gradeSheet.sectionSubject.section'])->latest('id')->paginate(25)];
    }

    public function createRelease(User $actor, array $input): GradeReleaseSchedule
    {
        return DB::transaction(function () use ($actor, $input) {
            $sheets = GradeSheet::lockForUpdate()->whereIn('id', $input['grade_sheet_ids'])->get();
            abort_unless($sheets->count() === count(array_unique($input['grade_sheet_ids'])), 422, 'One or more grade sheets were not found.');
            foreach ($sheets as $sheet) {
                abort_unless($sheet->status === 'approved', 409, 'Only Approved grade sheets can be scheduled.');
                $section = $sheet->sectionSubject()->with('section')->firstOrFail()->section;
                abort_unless($section->academic_year_id === (int) $input['academic_year_id'] && $section->semester_id === (int) $input['semester_id'], 422, 'All grade sheets must belong to the release term.');
                $this->assertSnapshotValid($sheet);
            }
            $release = GradeReleaseSchedule::create(['academic_year_id' => $input['academic_year_id'], 'semester_id' => $input['semester_id'], 'release_at' => $input['release_at'], 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($sheets as $sheet) {
                GradeReleaseItem::create(['grade_release_schedule_id' => $release->id, 'grade_sheet_id' => $sheet->id]);
            }
            $this->grading->audit($actor, 'grade_release.created', null, null, ['release_schedule_id' => $release->id, 'grade_sheet_ids' => $sheets->pluck('id')->all(), 'release_at' => $release->release_at]);

            return $release->load(['items.gradeSheet']);
        });
    }

    public function updateRelease(User $actor, GradeReleaseSchedule $release, array $input): GradeReleaseSchedule
    {
        return DB::transaction(function () use ($actor, $release, $input) {
            $locked = GradeReleaseSchedule::lockForUpdate()->findOrFail($release->id);
            abort_unless($locked->status === 'scheduled', 409, 'Released schedules cannot be edited.');
            abort_unless($locked->version === (int) $input['version'], 409, 'This release schedule changed. Reload before editing.');
            $locked->update(['release_at' => $input['release_at'], 'updated_by' => $actor->id, 'version' => $locked->version + 1]);
            $this->grading->audit($actor, 'grade_release.updated', null, null, ['release_schedule_id' => $locked->id, 'release_at' => $locked->release_at, 'version' => $locked->version]);

            return $locked->fresh(['items.gradeSheet']);
        });
    }

    public function executeRelease(GradeReleaseSchedule $release, ?User $actor = null, bool $manual = false): GradeReleaseSchedule
    {
        return DB::transaction(function () use ($release, $actor, $manual) {
            $locked = GradeReleaseSchedule::lockForUpdate()->findOrFail($release->id);
            if ($locked->status === 'released') {
                return $locked->load('items.gradeSheet');
            }
            abort_unless($manual || now()->greaterThanOrEqualTo($locked->release_at), 409, 'The scheduled release time has not been reached.');
            $executionActor = $actor ?? $locked->creator()->firstOrFail();
            $items = GradeReleaseItem::where('grade_release_schedule_id', $locked->id)->lockForUpdate()->get();
            abort_unless($items->isNotEmpty(), 422, 'A release schedule requires at least one grade sheet.');
            foreach ($items as $item) {
                $sheet = GradeSheet::lockForUpdate()->findOrFail($item->grade_sheet_id);
                if ($sheet->status === 'published') {
                    continue;
                }
                abort_unless($sheet->status === 'approved', 409, 'Every scheduled grade sheet must still be Approved.');
                $this->assertSnapshotValid($sheet);
                $sheet->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $executionActor->id, 'version' => $sheet->version + 1])->save();
                $this->grading->audit($executionActor, 'grade_sheet.published', $sheet, null, ['release_schedule_id' => $locked->id, 'old_status' => 'approved', 'new_status' => 'published']);
            }
            $locked->forceFill(['status' => 'released', 'executed_at' => now(), 'executed_by' => $executionActor->id, 'updated_by' => $executionActor->id, 'version' => $locked->version + 1])->save();
            $this->grading->audit($executionActor, 'grade_release.executed', null, null, ['release_schedule_id' => $locked->id]);

            return $locked->fresh(['items.gradeSheet']);
        });
    }

    public function processDue(): int
    {
        $count = 0;
        GradeReleaseSchedule::where('status', 'scheduled')->where('release_at', '<=', now())->orderBy('id')->each(function ($release) use (&$count) {
            $this->executeRelease($release);
            $count++;
        });

        return $count;
    }

    private function assertSnapshotValid(GradeSheet $sheet): void
    {
        $attempt = $sheet->latestSubmission()->with('students')->first();
        abort_unless($attempt, 422, 'The grade sheet has no submission snapshot.');
        $results = $attempt->students->map(fn ($row) => $row->only(['enrollment_subject_id', 'student_id', 'student_number', 'student_name', 'midterm_grade', 'finals_grade', 'final_grade', 'grade_point', 'remarks', 'breakdown']))->all();
        abort_unless(hash_equals($attempt->checksum, $this->checksum($attempt->configuration, $results)), 409, 'The submission snapshot failed its integrity check.');
        $current = $this->compute($sheet);
        $currentConfiguration = $current['configuration'];
        if (! array_key_exists('context', $attempt->configuration)) {
            unset($currentConfiguration['context']);
        }
        $currentChecksum = $this->checksum($currentConfiguration, $current['results']);
        abort_unless($current['ready'] && hash_equals($attempt->checksum, $currentChecksum), 409, 'The submitted grading data changed. Return and resubmit the sheet.');
    }

    private function roster(GradeSheet $sheet)
    {
        $assignment = $sheet->sectionSubject;

        return EnrollmentSubject::query()->from('enrollment_subjects as es')->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')->join('students as s', 's.id', '=', 'e.student_id')->join('user_profiles as up', 'up.id', '=', 's.user_profile_id')->where('e.section_id', $assignment->section_id)->where('es.subject_id', $assignment->subject_id)->whereIn('e.status', ['enrolled', 'completed'])->whereIn('es.subject_status', ['enrolled', 'completed'])->orderBy('up.last_name')->orderBy('up.first_name')->select(['es.id as enrollment_subject_id', 's.id as student_id', 's.student_number', 'up.first_name', 'up.middle_name', 'up.last_name']);
    }

    private function checksum(array $configuration, array $results): string
    {
        return hash('sha256', json_encode($this->canonical(['configuration' => $configuration, 'students' => array_values($results)]), JSON_UNESCAPED_SLASHES));
    }

    private function contextSnapshot(GradeSheet $sheet): array
    {
        $assignment = $sheet->sectionSubject;
        $section = $assignment->section;
        $profile = $sheet->professor?->user?->profile;

        return [
            'academic_year_id' => $section->academic_year_id,
            'academic_year' => $section->academicYear?->school_year,
            'semester_id' => $section->semester_id,
            'semester' => $section->semester?->semester_name,
            'course_id' => $section->course_id,
            'course' => $section->course?->course_code ?? $section->course?->course_name,
            'year_level' => $section->year_level,
            'section_id' => $section->id,
            'section' => $section->section_name,
            'subject_id' => $assignment->subject_id,
            'subject_code' => $assignment->subject?->subject_code,
            'subject_name' => $assignment->subject?->subject_name,
            'units' => (float) ($assignment->subject?->units ?? 0),
            'professor_id' => $sheet->professor_id,
            'professor' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')),
        ];
    }

    private function canonical(mixed $value): mixed
    {
        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 2, '.', '');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function scaled(float|int|string|null $value): int
    {
        return (int) round(((float) $value) * 100, 0, PHP_ROUND_HALF_UP);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\GradeAssessment;
use App\Models\GradePeriodSchedule;
use App\Models\GradeReleaseSchedule;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Services\Grading\GradingService;
use App\Services\Grading\GradingWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradingController extends Controller
{
    public function __construct(private GradingService $grading, private GradingWorkflowService $workflow) {}

    private function ok(mixed $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }

    public function periods(Request $request)
    {
        $input = $request->validate(['academic_year_id' => 'nullable|integer', 'semester_id' => 'nullable|integer', 'status' => ['nullable', Rule::in(['active', 'archived'])]]);
        $query = GradePeriodSchedule::with(['academicYear', 'semester']);
        foreach ($input as $key => $value) {
            if ($value !== null && $value !== '') {
                $query->where($key, $value);
            }
        }
        $items = $query->latest('id')->paginate(25);
        $items->getCollection()->transform(fn ($row) => $this->grading->scheduleData($row));

        return $this->ok(['periods' => $items, 'academic_years' => AcademicYear::orderByDesc('start_date')->limit(50)->get(), 'semesters' => Semester::orderBy('semester_order')->get(), 'courses' => Course::orderBy('course_code')->limit(300)->get(['id', 'course_code', 'course_name']), 'sections' => Section::orderByDesc('id')->limit(500)->get(['id', 'section_name', 'year_level', 'course_id', 'academic_year_id', 'semester_id']), 'subjects' => Subject::orderBy('subject_code')->limit(500)->get(['id', 'subject_code', 'subject_name']), 'professors' => Professor::with('user.profile')->where('status', 'active')->limit(300)->get()]);
    }

    public function storePeriod(Request $request)
    {
        $input = $this->periodInput($request);
        $period = DB::transaction(function () use ($request, $input) {
            $period = GradePeriodSchedule::create($input + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->grading->audit($request->user(), 'grading_period.created', null, $period, ['new' => $period->only(array_keys($input))]);

            return $period;
        });

        return $this->ok($this->grading->scheduleData($period->load(['academicYear', 'semester'])), 201);
    }

    public function updatePeriod(Request $request, GradePeriodSchedule $gradePeriodSchedule)
    {
        $input = $this->periodInput($request, true);
        $period = DB::transaction(function () use ($request, $input, $gradePeriodSchedule) {
            $period = GradePeriodSchedule::lockForUpdate()->findOrFail($gradePeriodSchedule->id);
            abort_unless($period->version === (int) $input['version'], 409, 'This grade period changed. Reload before continuing.');
            $old = $period->only(['academic_year_id', 'semester_id', 'midterm_opens_at', 'midterm_deadline', 'finals_opens_at', 'finals_deadline', 'status']);
            $termChanged = (int) $period->academic_year_id !== (int) $input['academic_year_id']
                || (int) $period->semester_id !== (int) $input['semester_id'];
            abort_if($termChanged && $this->termHasGradingActivity($period), 409, 'The academic term cannot be changed because grading activity already exists. Edit the schedule dates instead.');

            $datesChanged = collect(['midterm_opens_at', 'midterm_deadline', 'finals_opens_at', 'finals_deadline'])
                ->contains(fn ($field) => ! $period->{$field}->equalTo($input[$field]));
            abort_if($datesChanged && $this->termHasLockedGradingHistory($period), 409, 'Grade period dates cannot be changed because submitted, approved, or published grading history already exists.');

            unset($input['version']);
            $period->fill($input + ['updated_by' => $request->user()->id]);
            $period->version++;
            $period->save();
            $this->grading->audit($request->user(), 'grading_period.updated', null, $period, ['old' => $old, 'new' => $period->only(array_keys($old))]);

            return $period;
        });

        return $this->ok($this->grading->scheduleData($period->load(['academicYear', 'semester'])));
    }

    public function destroyPeriod(Request $request, GradePeriodSchedule $gradePeriodSchedule)
    {
        $input = $request->validate(['version' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $input, $gradePeriodSchedule) {
            $period = GradePeriodSchedule::lockForUpdate()->findOrFail($gradePeriodSchedule->id);
            abort_unless($period->version === (int) $input['version'], 409, 'This grade period changed. Reload before continuing.');
            abort_if($this->termHasGradingActivity($period), 409, 'This grade period cannot be deleted because grading activity already exists. Edit the schedule instead.');

            $snapshot = $period->only(['id', 'academic_year_id', 'semester_id', 'midterm_opens_at', 'midterm_deadline', 'finals_opens_at', 'finals_deadline', 'status', 'version']);
            $this->grading->audit($request->user(), 'grading_period.deleted', null, $period, ['deleted' => $snapshot]);
            $period->delete();
        });

        return $this->ok(null);
    }

    public function archivePeriod(Request $request, GradePeriodSchedule $gradePeriodSchedule)
    {
        $input = $request->validate(['version' => 'required|integer|min:1']);
        $period = DB::transaction(function () use ($request, $input, $gradePeriodSchedule) {
            $period = GradePeriodSchedule::lockForUpdate()->findOrFail($gradePeriodSchedule->id);
            abort_unless($period->version === (int) $input['version'], 409, 'This grade period changed. Reload before continuing.');
            $period->update(['status' => 'archived', 'updated_by' => $request->user()->id, 'version' => $period->version + 1]);
            $this->grading->audit($request->user(), 'grading_period.archived', null, $period);

            return $period;
        });

        return $this->ok($this->grading->scheduleData($period));
    }

    public function classes(Request $request)
    {
        $input = $request->validate(['search' => 'nullable|string|max:100', 'academic_year_id' => 'nullable|integer', 'semester_id' => 'nullable|integer']);

        return $this->ok($this->grading->classes($request->user(), $input));
    }

    public function workspace(Request $request, SectionSubject $sectionSubject)
    {
        return $this->ok($this->grading->openWorkspace($request->user(), $sectionSubject));
    }

    public function storeAssessment(Request $request, GradeSheet $gradeSheet)
    {
        return $this->ok($this->grading->saveAssessment($request->user(), $gradeSheet, $request->validate($this->assessmentRules())), 201);
    }

    public function storeAssessmentsBulk(Request $request, GradeSheet $gradeSheet)
    {
        $input = $request->validate([
            'period' => ['required', Rule::in(['midterm', 'finals'])],
            'assessments' => ['required', 'array', 'min:1', 'max:100'],
            'assessments.*.label' => ['required', 'string', 'max:80'],
            'assessments.*.category' => ['required', Rule::in(GradingService::CATEGORIES)],
            'assessments.*.max_score' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'assessments.*.display_order' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        return $this->ok($this->grading->saveAssessmentsBulk($request->user(), $gradeSheet, $input), 201);
    }

    public function updateAssessment(Request $request, GradeSheet $gradeSheet, GradeAssessment $gradeAssessment)
    {
        abort_unless($gradeAssessment->grade_sheet_id === $gradeSheet->id, 404);

        return $this->ok($this->grading->saveAssessment($request->user(), $gradeSheet, $request->validate($this->assessmentRules(true)), $gradeAssessment));
    }

    public function assessmentStatus(Request $request, GradeSheet $gradeSheet, GradeAssessment $gradeAssessment)
    {
        abort_unless($gradeAssessment->grade_sheet_id === $gradeSheet->id, 404);
        $input = $request->validate(['version' => 'required|integer|min:1', 'status' => ['required', Rule::in(['active', 'archived'])]]);

        return $this->ok($this->grading->assessmentStatus($request->user(), $gradeSheet, $gradeAssessment, $input));
    }

    public function scores(Request $request, GradeSheet $gradeSheet)
    {
        $input = $request->validate(['assessment_id' => 'required|integer|exists:grade_assessments,id', 'scores' => 'required|array|min:1|max:500', 'scores.*.enrollment_subject_id' => 'required|integer|distinct', 'scores.*.score' => 'required|numeric|min:0', 'scores.*.version' => 'nullable|integer|min:1']);

        return $this->ok($this->grading->saveScores($request->user(), $gradeSheet, $input));
    }

    public function weights(Request $request, GradeSheet $gradeSheet, string $period)
    {
        abort_unless(in_array($period, ['midterm', 'finals'], true), 404);
        $input = $request->validate(['weights' => 'required|array|size:4', 'weights.*.category' => ['required', 'distinct', Rule::in(GradingService::CATEGORIES)], 'weights.*.weight_percentage' => 'required|numeric|min:0|max:100', 'weights.*.version' => 'required|integer|min:1']);

        return $this->ok($this->grading->updateWeights($request->user(), $gradeSheet, $period, $input['weights']));
    }

    public function readiness(Request $request, GradeSheet $gradeSheet)
    {
        return $this->ok($this->workflow->readiness($request->user(), $gradeSheet));
    }

    public function finalWeights(Request $request, GradeSheet $gradeSheet)
    {
        $input = $request->validate(['midterm_weight' => 'required|numeric|min:0|max:100', 'finals_weight' => 'required|numeric|min:0|max:100', 'version' => 'required|integer|min:1']);

        return $this->ok($this->workflow->updateFinalWeights($request->user(), $gradeSheet, $input));
    }

    public function submit(Request $request, GradeSheet $gradeSheet)
    {
        $input = $request->validate(['version' => 'required|integer|min:1']);

        return $this->ok($this->workflow->submit($request->user(), $gradeSheet, (int) $input['version']));
    }

    public function reviews(Request $request)
    {
        $input = $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['submitted', 'approved', 'published'])], 'academic_year_id' => 'nullable|integer', 'semester_id' => 'nullable|integer', 'course_id' => 'nullable|integer', 'year_level' => 'nullable|integer|min:1|max:20', 'section_id' => 'nullable|integer', 'subject_id' => 'nullable|integer', 'professor_id' => 'nullable|integer']);

        return $this->ok($this->workflow->reviewList($input));
    }

    public function reviewDetail(GradeSheet $gradeSheet)
    {
        return $this->ok($this->workflow->reviewDetail($gradeSheet));
    }

    public function review(Request $request, GradeSheet $gradeSheet, string $action)
    {
        abort_unless(in_array($action, ['approve', 'return'], true), 404);
        $input = $request->validate(['version' => 'required|integer|min:1', 'reason' => $action === 'return' ? 'required|string|min:3|max:2000' : 'nullable|string|max:2000']);

        return $this->ok($this->workflow->review($request->user(), $gradeSheet, $action, (int) $input['version'], $input['reason'] ?? null));
    }

    public function releases()
    {
        return $this->ok($this->workflow->releaseList());
    }

    public function storeRelease(Request $request)
    {
        $input = $request->validate(['academic_year_id' => 'required|integer|exists:academic_years,id', 'semester_id' => 'required|integer|exists:semesters,id', 'release_at' => 'required|date', 'grade_sheet_ids' => 'required|array|min:1|max:200', 'grade_sheet_ids.*' => 'required|integer|distinct|exists:grade_sheets,id']);

        return $this->ok($this->workflow->createRelease($request->user(), $input), 201);
    }

    public function updateRelease(Request $request, GradeReleaseSchedule $gradeReleaseSchedule)
    {
        $input = $request->validate(['release_at' => 'required|date', 'version' => 'required|integer|min:1']);

        return $this->ok($this->workflow->updateRelease($request->user(), $gradeReleaseSchedule, $input));
    }

    public function executeRelease(Request $request, GradeReleaseSchedule $gradeReleaseSchedule)
    {
        return $this->ok($this->workflow->executeRelease($gradeReleaseSchedule, $request->user(), true));
    }

    private function periodInput(Request $request, bool $updating = false): array
    {
        $ignore = $updating ? $request->route('gradePeriodSchedule')?->id : null;

        return $request->validate(
            ['academic_year_id' => ['required', 'integer', 'exists:academic_years,id', Rule::unique('grade_period_schedules')->where(fn ($q) => $q->where('semester_id', $request->input('semester_id')))->ignore($ignore)], 'semester_id' => 'required|integer|exists:semesters,id', 'midterm_opens_at' => 'required|date', 'midterm_deadline' => 'required|date|after:midterm_opens_at', 'finals_opens_at' => 'required|date', 'finals_deadline' => 'required|date|after:finals_opens_at', 'status' => ['sometimes', Rule::in(['active', 'archived'])], 'version' => $updating ? 'required|integer|min:1' : 'sometimes|integer'],
            [
                'academic_year_id.required' => 'Select an academic year.',
                'academic_year_id.exists' => 'The selected academic year is unavailable.',
                'academic_year_id.unique' => 'A grade period schedule already exists for the selected academic term.',
                'semester_id.required' => 'Select a semester.',
                'semester_id.exists' => 'The selected semester is unavailable.',
                'midterm_opens_at.required' => 'Enter when Midterm grading opens.',
                'midterm_deadline.required' => 'Enter the Midterm grading deadline.',
                'midterm_deadline.after' => 'The Midterm deadline must be after its opening date.',
                'finals_opens_at.required' => 'Enter when Finals grading opens.',
                'finals_deadline.required' => 'Enter the Finals grading deadline.',
                'finals_deadline.after' => 'The Finals deadline must be after its opening date.',
            ],
        );
    }

    private function termHasGradingActivity(GradePeriodSchedule $period): bool
    {
        return $this->termGradeSheets($period)->exists()
            || GradeReleaseSchedule::where('academic_year_id', $period->academic_year_id)
                ->where('semester_id', $period->semester_id)
                ->exists();
    }

    private function termHasLockedGradingHistory(GradePeriodSchedule $period): bool
    {
        return $this->termGradeSheets($period)
            ->whereIn('grade_sheets.status', ['submitted', 'approved', 'published'])
            ->exists();
    }

    private function termGradeSheets(GradePeriodSchedule $period)
    {
        return GradeSheet::query()->whereHas('sectionSubject.section', fn ($section) => $section
            ->where('academic_year_id', $period->academic_year_id)
            ->where('semester_id', $period->semester_id));
    }

    private function assessmentRules(bool $updating = false): array
    {
        return ['period' => ['required', Rule::in(['midterm', 'finals'])], 'label' => 'required|string|max:80', 'category' => ['required', Rule::in(GradingService::CATEGORIES)], 'max_score' => 'required|numeric|gt:0|max:999999.99', 'display_order' => 'required|integer|min:1|max:65535', 'version' => $updating ? 'required|integer|min:1' : 'sometimes|integer'];
    }
}

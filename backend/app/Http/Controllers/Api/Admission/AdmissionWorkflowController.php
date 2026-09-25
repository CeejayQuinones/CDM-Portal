<?php

namespace App\Http\Controllers\Api\Admission;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionProgramSetting;
use App\Models\Admission\AdmissionWorkflowEvent;
use App\Models\Course;
use App\Services\Admission\AdmissionConfigurationService;
use App\Services\Admission\AdmissionConversionService;
use App\Services\Admission\AdmissionExamService;
use App\Services\Admission\AdmissionRecommendationService;
use App\Services\Admission\AdmissionResultService;
use App\Services\Admission\ProgramMatcher;
use Illuminate\Http\Request;

class AdmissionWorkflowController extends Controller
{
    private function ok(mixed $data)
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function cycles()
    {
        return $this->ok(['cycles' => AdmissionCycle::orderByDesc('id')->get(), 'academic_years' => AcademicYear::orderByDesc('id')->get(['id', 'school_year'])]);
    }

    public function saveCycle(Request $request, AdmissionConfigurationService $service, ?int $id = null)
    {
        return $this->ok($service->cycle($request->user(), $request->all(), $id));
    }

    public function questions(Request $request)
    {
        $query = AdmissionExamQuestion::query();
        if ($request->filled('topic')) {
            $query->where('topic', $request->string('topic')->toString());
        }
        if ($request->filled('search')) {
            $query->where('question_text', 'like', '%'.$request->string('search')->toString().'%');
        }

        return $this->ok(['questions' => $query->orderBy('id')->paginate(25),
            'categories' => ProgramMatcher::INTEREST_CATEGORIES,
            'counts' => AdmissionExamQuestion::where('status', 'active')->selectRaw('topic, count(*) as total')->groupBy('topic')->pluck('total', 'topic')]);
    }

    public function saveQuestion(Request $request, AdmissionConfigurationService $service, ?int $id = null)
    {
        return $this->ok($service->question($request->user(), $request->all(), $id));
    }

    public function deleteQuestion(Request $request, AdmissionConfigurationService $service, int $id)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $service->deleteQuestion($request->user(), $id, $data['version']);

        return $this->ok(['deleted' => true]);
    }

    public function programs()
    {
        return $this->ok(['courses' => Course::orderBy('course_code')->get(['id', 'course_code', 'course_name', 'status']), 'settings' => AdmissionProgramSetting::all(), 'categories' => ProgramMatcher::INTEREST_CATEGORIES]);
    }

    public function saveProgram(Request $request, AdmissionConfigurationService $service, int $id)
    {
        return $this->ok($service->program($request->user(), $id, $request->all()));
    }

    public function exam(Request $request, AdmissionExamService $service)
    {
        return $this->ok($service->status($request->user()));
    }

    public function start(Request $request, AdmissionExamService $service)
    {
        return $this->ok($service->start($request->user()));
    }

    public function save(Request $request, AdmissionExamService $service, string $id)
    {
        return $this->write($request, $service, $id, false);
    }

    public function submit(Request $request, AdmissionExamService $service, string $id)
    {
        return $this->write($request, $service, $id, true);
    }

    private function write(Request $request, AdmissionExamService $service, string $id, bool $submit)
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'answers' => 'required|array|size:100', 'answers.*' => 'nullable|in:A,B,C,D', 'position' => 'required|integer|between:0,99']);

        return $this->ok($service->write($request->user(), $id, $data, $submit));
    }

    public function result(Request $request, AdmissionExamService $service)
    {
        return $this->ok($service->published($request->user()));
    }

    public function recommendation(Request $request, AdmissionRecommendationService $service)
    {
        $interests = null;
        if ($request->isMethod('post')) {
            $rules = ['interests' => ['required', 'array:'.implode(',', ProgramMatcher::INTEREST_CATEGORIES)]];
            foreach (ProgramMatcher::INTEREST_CATEGORIES as $category) {
                $rules['interests.'.$category] = 'required|integer|between:1,5';
            }
            $interests = $request->validate($rules)['interests'];
        }

        return $this->ok($service->recommend($request->user(), $interests));
    }

    public function applicants(Request $request)
    {
        $query = AdmissionApplicant::with(['cycle:id,code,name', 'user.profile']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->integer('cycle_id'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($q) => $q->where('applicant_number', 'like', $search)->orWhereHas('user.profile', fn ($p) => $p->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)));
        }
        $page = $query->latest('id')->paginate(25);
        $page->through(fn ($a) => ['id' => $a->id, 'applicant_number' => $a->applicant_number, 'name' => trim($a->user->profile?->first_name.' '.$a->user->profile?->last_name), 'status' => $a->status, 'cycle' => $a->cycle->name, 'created_at' => $a->created_at]);

        return $this->ok($page);
    }

    public function conversion(Request $request, AdmissionConversionService $service, int $id)
    {
        return $this->ok($service->inspect($request->user(), $id));
    }

    public function acceptApplicant(Request $request, AdmissionConversionService $service, int $id)
    {
        return $this->ok($service->accept($request->user(), $id, $request->all()));
    }

    public function convertApplicant(Request $request, AdmissionConversionService $service, int $id)
    {
        return $this->ok($service->convert($request->user(), $id, $request->all()));
    }

    public function results(Request $request)
    {
        $query = AdmissionExamResult::with('session.applicant.user.profile', 'session.applicant.cycle');
        if ($request->filled('status')) {
            $query->where('official_status', $request->string('status')->toString());
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->whereHas('session.applicant', fn ($q) => $q->where('applicant_number', 'like', $search)->orWhereHas('user.profile', fn ($p) => $p->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)));
        }
        if ($request->filled('cycle_id')) {
            $query->whereHas('session.applicant', fn ($q) => $q->where('cycle_id', $request->integer('cycle_id')));
        }
        if ($request->boolean('latest')) {
            $query->whereHas('session', fn ($q) => $q->whereRaw('attempt_number = (select max(s.attempt_number) from admission_exam_sessions s where s.applicant_id = admission_exam_sessions.applicant_id)'));
        }
        $page = $query->latest('id')->paginate(25);
        $page->through(function ($r) {
            $a = $r->session->applicant;

            return $r->only(['id', 'system_percentage', 'system_passed', 'official_score', 'official_status', 'registrar_pass', 'internal_reason', 'version', 'published_at', 'finalized_at', 'category_scores', 'category_maximums'])
                + ['attempt_number' => $r->session->attempt_number, 'applicant_number' => $a->applicant_number, 'name' => trim($a->user->profile?->first_name.' '.$a->user->profile?->last_name), 'cycle' => $a->cycle->name, 'outcome' => $r->outcome()];
        });

        return $this->ok($page);
    }

    public function review(Request $request, AdmissionResultService $service, string $action)
    {
        $data = $request->validate([
            'results' => 'required|array|min:1|max:100', 'results.*.id' => 'required|integer|distinct', 'results.*.version' => 'required|integer|min:1',
            'official_score' => 'nullable|integer|between:0,100', 'reason' => 'nullable|string|max:2000',
        ]);

        return $this->ok($service->act($request->user(), $data['results'], $action, $data['official_score'] ?? null, $data['reason'] ?? null));
    }

    public function history(Request $request)
    {
        $query = AdmissionDecision::query();
        if ($request->filled('result_id')) {
            $query->where('result_id', $request->integer('result_id'));
        }

        return $this->ok(['decisions' => $query->latest('id')->paginate(25), 'events' => AdmissionWorkflowEvent::whereNotNull('applicant_id')->latest('id')->limit(50)->get()]);
    }
}

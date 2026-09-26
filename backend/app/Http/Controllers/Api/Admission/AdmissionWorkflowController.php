<?php

namespace App\Http\Controllers\Api\Admission;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Admission\AdmissionProgramSetting;
use App\Models\Course;
use App\Models\Department;
use App\Models\User;
use App\Services\Admission\AdmissionConfigurationService;
use App\Services\Admission\AdmissionConversionService;
use App\Services\Admission\AdmissionExamPolicy;
use App\Services\Admission\AdmissionExamService;
use App\Services\Admission\AdmissionRecommendationService;
use App\Services\Admission\AdmissionResultService;
use App\Services\Admission\ProgramMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function exams()
    {
        return $this->ok(['exams' => AdmissionCycle::orderByDesc('id')->get()->map(fn ($cycle) => AdmissionExamPolicy::configuration($cycle))]);
    }

    public function saveExam(Request $request, AdmissionConfigurationService $service, int $id)
    {
        return $this->ok($service->exam($request->user(), $id, $request->all()));
    }

    public function saveCourse(Request $request, AdmissionConfigurationService $service, ?int $id = null)
    {
        return $this->ok($service->course($request->user(), $request->all(), $id));
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

        foreach (['status', 'difficulty'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }
        $cycle = $request->filled('cycle_id') ? AdmissionCycle::findOrFail($request->integer('cycle_id')) : null;
        $policy = AdmissionExamPolicy::normalize($cycle?->exam_policy);

        return $this->ok(['readiness' => AdmissionExamPolicy::readiness($policy), 'cycles' => AdmissionCycle::latest('id')->get(['id', 'name']), 'questions' => $query->orderBy('id')->paginate(25),
            'categories' => ProgramMatcher::INTEREST_CATEGORIES,
            'counts' => AdmissionExamPolicy::readiness($policy)['counts']]);
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

    public function programs(Request $request)
    {
        $query = Course::with('department:id,department_name');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($q) => $q->where('course_code', 'like', $search)->orWhere('course_name', 'like', $search));
        }

        return $this->ok(['courses' => $query->orderBy('course_code')->get(),
            'departments' => Department::orderBy('department_name')->get(['id', 'department_name']),
            'settings' => AdmissionProgramSetting::all(), 'categories' => ProgramMatcher::INTEREST_CATEGORIES]);
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
        $data = $request->validate(['revision' => 'required|integer|min:0', 'answers' => 'required|array|min:5|max:500', 'answers.*' => 'nullable|in:A,B,C,D', 'position' => 'required|integer|between:0,499']);

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
        $page->through(fn ($a) => $this->applicantSummary($a));

        return $this->ok($page);
    }

    private function applicantSummary(AdmissionApplicant $a): array
    {
        $latest = AdmissionExamSession::where('applicant_id', $a->id)->orderByDesc('attempt_number')->with('result')->first();

        return ['id' => $a->id, 'applicant_number' => $a->applicant_number,
            'name' => trim($a->user->profile?->first_name.' '.$a->user->profile?->last_name),
            'status' => $a->status, 'cycle' => $a->cycle->name, 'created_at' => $a->created_at,
            'exam_status' => $latest?->status ?? 'not_started', 'latest_result' => $latest?->result?->outcome() ?? 'Not available',
            'acceptance_status' => $a->accepted_at ? 'accepted' : 'not_accepted',
            'conversion_status' => $a->converted_student_id ? 'converted' : 'not_converted'];
    }

    public function applicant(Request $request, int $id)
    {
        $a = AdmissionApplicant::with('user.profile', 'cycle')->findOrFail($id);
        $attempts = AdmissionExamSession::where('applicant_id', $id)->orderBy('attempt_number')->with('result')->get()
            ->map(fn ($s) => ['attempt_number' => $s->attempt_number, 'status' => $s->status, 'started_at' => $s->started_at,
                'finalized_at' => $s->finalized_at, 'result_id' => $s->result?->id, 'outcome' => $s->result?->outcome(),
                'system_percentage' => $s->result?->system_percentage, 'official_score' => $s->result?->official_score,
                'version' => $s->result?->version]);

        return $this->ok($this->applicantSummary($a) + ['attempts' => $attempts, 'accepted_at' => $a->accepted_at, 'converted_at' => $a->converted_at]);
    }

    public function registrarCycles()
    {
        return $this->ok(AdmissionCycle::latest('id')->get(['id', 'name']));
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

    public function results(Request $request, AdmissionResultService $service)
    {
        $query = AdmissionExamResult::with('session.applicant.user.profile', 'session.applicant.cycle');
        if ($request->filled('attempt')) {
            $query->whereHas('session', fn ($q) => $q->where('attempt_number', $request->integer('attempt')));
        }
        if ($request->filled('tab') && in_array($request->input('tab'), ['pending', 'approved', 'published'], true)) {
            $query->where('official_status', $request->input('tab'));
        }
        if ($request->input('tab') === 'retake') {
            // Numeric JSON extraction works on SQLite and MySQL/MariaDB; legacy snapshots use MVP defaults.
            $query->where('official_status', 'published')->where('registrar_pass', false)
                ->whereHas('session', fn ($q) => $q
                    ->whereRaw("attempt_number < CAST(COALESCE(JSON_EXTRACT(policy_snapshot, '$.max_attempts'), 2) AS SIGNED)")
                    ->whereRaw("admission_exam_results.official_score < CAST(COALESCE(JSON_EXTRACT(policy_snapshot, '$.passing_score'), 75) AS SIGNED)"));
        }
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
        $page->through(function ($r) use ($service) {
            $a = $r->session->applicant;

            return $r->only(['id', 'system_percentage', 'system_passed', 'official_score', 'official_status', 'registrar_pass', 'internal_reason', 'version', 'published_at', 'finalized_at', 'category_scores', 'category_maximums'])
                + ['allowed_actions' => $service->allowedActions($r), 'attempt_number' => $r->session->attempt_number, 'applicant_number' => $a->applicant_number, 'name' => trim($a->user->profile?->first_name.' '.$a->user->profile?->last_name), 'cycle' => $a->cycle->name, 'outcome' => $r->outcome()];
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
        // Union the immutable identity and workflow ledgers without copying historical rows.
        $identity = DB::table('admission_audit_events')->selectRaw("id, actor_user_id, applicant_id, action, created_at, metadata, 'identity' as source, 'applicant' as subject_type, CAST(applicant_id AS CHAR) as subject_id");
        $workflow = DB::table('admission_workflow_events')->selectRaw("id, actor_user_id, applicant_id, action, created_at, metadata, 'workflow' as source, subject_type, subject_id");
        $query = DB::query()->fromSub($identity->unionAll($workflow), 'timeline');
        if ($request->filled('applicant_id')) {
            $query->where('applicant_id', $request->integer('applicant_id'));
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('source')->orderByDesc('id')->paginate(25);
        $actors = User::with('profile')->whereIn('id', $page->pluck('actor_user_id')->filter())->get()->keyBy('id');
        $applicants = AdmissionApplicant::with('cycle', 'user.profile')->whereIn('id', $page->pluck('applicant_id')->filter())->get()->keyBy('id');
        $cycles = AdmissionCycle::whereIn('id', $page->filter(fn ($e) => $e->subject_type === 'cycle')->pluck('subject_id'))->get()->keyBy('id');
        $page->through(function ($event) use ($actors, $applicants, $cycles) {
            $actor = $actors->get($event->actor_user_id);
            $a = $applicants->get($event->applicant_id);
            $metadata = json_decode($event->metadata ?? '{}', true) ?? [];
            $label = match ($event->action) {
                'admission.exam_started' => ($metadata['attempt_number'] ?? 1) > 1 ? 'Retake started' : 'Exam started',
                'admission.identity_created' => 'Application created',
                'admission.registrar_pass' => 'Exceptional pass',
                default => ucfirst(str_replace('_', ' ', str_replace('admission.', '', $event->action))),
            };

            return ['id' => $event->source.'-'.$event->id, 'created_at' => Carbon::parse($event->created_at)->toISOString(),
                'actor' => $actor ? trim($actor->profile?->first_name.' '.$actor->profile?->last_name) ?: 'Staff account '.$actor->id : 'System',
                'action' => $label, 'applicant_number' => $a?->applicant_number,
                'subject' => $a ? trim($a->user->profile?->first_name.' '.$a->user->profile?->last_name) : ucfirst($event->subject_type).' '.$event->subject_id,
                'cycle' => $a?->cycle?->name ?? ($event->subject_type === 'cycle' ? $cycles->get($event->subject_id)?->name : null),
                'summary' => $label.' recorded for '.($a?->applicant_number ?? $event->subject_type.' '.$event->subject_id).'.'];
        });
        // Preserve the existing Registrar-only decision API for its callers.
        $decisions = AdmissionDecision::query()->when($request->filled('result_id'), fn ($q) => $q->where('result_id', $request->integer('result_id')))->latest('id')->paginate(25);

        return $this->ok(['timeline' => $page, 'decisions' => $decisions]);
    }
}

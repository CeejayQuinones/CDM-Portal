<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\DocumentType;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Role;
use App\Models\Semester;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EnrollmentWorkflowController extends Controller
{
    public function __construct(private EnrollmentWorkflowService $workflow) {}

    private function ok($data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function classification(): array
    {
        return ['required', Rule::in(['regular', 'irregular', 'transferee', 'returnee'])];
    }

    public function periods(Request $r)
    {
        EnrollmentAccess::require($r->user(), true);

        return $this->ok(['periods' => EnrollmentPeriod::with(['academicYear', 'semester'])->latest('id')->paginate(20),
            'academic_years' => AcademicYear::where('status', 'active')->orderByDesc('id')->get(['id', 'school_year']), 'semesters' => Semester::where('status', 'active')->get(['id', 'semester_name']),
            'document_types' => DocumentType::where('status', 'active')->orderBy('document_name')->get(['id', 'document_name'])]);
    }

    public function savePeriod(Request $r, ?int $id = null)
    {
        EnrollmentAccess::require($r->user(), true);
        $rules = ['academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('status', 'active')], 'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')->where('status', 'active')],
            'opens_at' => 'required|date', 'closes_at' => 'required|date|after:opens_at', 'enabled' => 'required|boolean', 'document_requirements' => 'required|array:regular,irregular,transferee,returnee', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer'];
        foreach (['regular', 'irregular', 'transferee', 'returnee'] as $c) {
            $rules['document_requirements.'.$c] = 'present|array|max:20';
            $rules['document_requirements.'.$c.'.*'] = ['integer', 'distinct', Rule::exists('document_types', 'id')->where('status', 'active')];
        }
        $input = $r->validate($rules);
        $input['academic_year_id'] = (int) $input['academic_year_id'];
        $input['semester_id'] = (int) $input['semester_id'];
        if ($id) {
            $input['version'] = (int) $input['version'];
        }
        foreach ($input['document_requirements'] as &$ids) {
            $ids = array_map('intval', $ids);
            sort($ids);
        }unset($ids);

        return $this->ok($this->workflow->period($r->user(), $input, $id), $id ? 200 : 201);
    }

    public function mine(Request $r)
    {
        $actor = EnrollmentAccess::require($r->user());

        return $this->ok(EnrollmentApplication::whereHas('student', fn ($q) => $q->where('user_id', $actor->id))->with(['period.academicYear', 'period.semester', 'course', 'curriculum'])->latest('id')->paginate(10));
    }

    public function create(Request $r)
    {
        EnrollmentAccess::require($r->user());
        $input = $r->validate(['period_id' => 'required|integer|exists:enrollment_periods,id', 'classification' => $this->classification()]);

        return $this->ok($this->workflow->create($r->user(), $input), 201);
    }

    public function index(Request $r)
    {
        EnrollmentAccess::require($r->user(), true);
        $in = $r->validate(['search' => 'nullable|string|max:100', 'course_id' => 'nullable|integer', 'period_id' => 'nullable|integer', 'classification' => ['nullable', Rule::in(['regular', 'irregular', 'transferee', 'returnee'])], 'status' => ['nullable', Rule::in(['draft', 'submitted', 'under_review', 'approved', 'enrolled', 'rejected', 'cancelled'])], 'sort' => ['nullable', Rule::in(['newest', 'oldest'])]]);
        $q = EnrollmentApplication::with(['student.userProfile', 'course', 'curriculum', 'period.academicYear', 'period.semester']);
        foreach (['course_id', 'period_id', 'classification', 'status'] as $key) {
            if (! empty($in[$key])) {
                $q->where($key, $in[$key]);
            }
        }
        if (! empty($in['search'])) {
            foreach (preg_split('/\s+/', trim($in['search']), -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part).'%';
                $q->whereHas('student', fn ($s) => $s->where(fn ($w) => $w->whereRaw("student_number LIKE ? ESCAPE '!'", [$search])->orWhereHas('userProfile', fn ($p) => $p->whereRaw("first_name LIKE ? ESCAPE '!'", [$search])->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$search]))));
            }
        }

        return $this->ok(['applications' => $q->orderBy('id', ($in['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->paginate(25),
            'courses' => Course::orderBy('course_name')->get(['id', 'course_name']), 'periods' => EnrollmentPeriod::with(['academicYear', 'semester'])->latest('id')->get()]);
    }

    private function authorized(Request $r, int $id): EnrollmentApplication
    {
        $staff = in_array($r->user()->fresh('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
        $actor = EnrollmentAccess::require($r->user(), $staff);

        return EnrollmentApplication::when(! $staff, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('user_id', $actor->id)))->findOrFail($id);
    }

    public function show(Request $r, int $id)
    {
        $a = $this->authorized($r, $id)->load(['student.userProfile', 'course', 'curriculum', 'period.academicYear', 'period.semester', 'documents']);

        return $this->ok(['application' => $a, 'requirements' => $this->workflow->requirements($a)]);
    }

    public function action(Request $r, int $id, string $action)
    {
        $staff = in_array($action, ['review', 'approve', 'reject'], true);
        EnrollmentAccess::require($r->user(), $staff);
        $rules = ['version' => 'required|integer|min:1'];
        if ($action === 'save') {
            $rules['classification'] = $this->classification();
        }
        if ($staff) {
            $rules['notes'] = ($action === 'reject' ? 'required' : 'nullable').'|string|max:3000';
        }
        if (in_array($action, ['submit', 'approve', 'reject', 'cancel'])) {
            $rules['confirmed'] = 'required|accepted';
        }
        $input = $r->validate($rules);
        $input['version'] = (int) $input['version'];

        return $this->ok($this->workflow->mutate($r->user(), $id, $action, $input));
    }

    public function upload(Request $r, int $id)
    {
        EnrollmentAccess::require($r->user());
        $input = $r->validate(['version' => 'required|integer|min:1', 'document_type_id' => 'required|integer', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $input['version'] = (int) $input['version'];
        $input['document_type_id'] = (int) $input['document_type_id'];

        return $this->ok($this->workflow->upload($r->user(), $id, $input, $r->file('file')), 201);
    }

    public function download(Request $r, int $id, int $document)
    {
        $a = $this->authorized($r, $id);
        $doc = $a->documents()->findOrFail($document);
        $path = $this->workflow->documentPath($a, $doc);
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Document unavailable.');

        return Storage::disk('local')->download($path, 'enrollment-document.'.pathinfo($path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff']);
    }
}

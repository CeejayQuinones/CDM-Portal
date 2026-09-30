<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Notifications\EnrollmentNotice;
use App\Services\Enrollment\EnrollmentAcademicService;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentSchedulingService;
use App\Services\Enrollment\EnrollmentSubjectPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnrollmentAcademicController extends Controller
{
    public function __construct(private EnrollmentAcademicService $academic, private EnrollmentSubjectPolicy $loads, private EnrollmentSchedulingService $scheduling) {}

    private function ok($data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function staff(Request $r)
    {
        return EnrollmentAccess::require($r->user(), true);
    }

    private function isStaff(Request $r): bool
    {
        return in_array($r->user()->fresh('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
    }

    private function counts($q)
    {
        return $q->withCount(['enrollments as enrolled_count' => fn ($q) => $q->whereIn('status', ['pending', 'enrolled']), 'applications as reserved_count' => fn ($q) => $q->where('status', 'approved')->whereNull('enrollment_id')]);
    }

    public function options(Request $r)
    {
        $this->staff($r);

        return $this->ok(['courses' => Course::where('status', 'active')->orderBy('course_name')->limit(300)->get(['id', 'course_name', 'years']), 'academic_years' => AcademicYear::where('status', 'active')->latest('id')->limit(50)->get(), 'semesters' => Semester::where('status', 'active')->get(), 'professors' => Professor::with('user.profile')->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')->whereHas('role', fn ($r) => $r->where('role_name', Role::PROFESSOR)))->orderBy('id')->paginate(100)]);
    }

    public function sections(Request $r)
    {
        $this->staff($r);
        $in = $r->validate(['search' => 'nullable|string|max:100', 'course_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer', 'semester_id' => 'nullable|integer', 'status' => ['nullable', Rule::in(['open', 'closed'])]]);
        $q = Section::with(['course', 'academicYear', 'semester']);
        foreach (['course_id', 'academic_year_id', 'semester_id', 'status'] as $key) {
            if (! empty($in[$key])) {
                $q->where($key, $in[$key]);
            }
        }if (! empty($in['search'])) {
            $q->where('section_name', 'like', '%'.$in['search'].'%');
        }

        return $this->ok($this->counts($q)->latest('id')->paginate(25));
    }

    public function saveSection(Request $r, ?int $id = null)
    {
        $this->staff($r);
        $in = $r->validate(['course_id' => 'required|integer|exists:courses,id', 'academic_year_id' => 'required|integer|exists:academic_years,id', 'semester_id' => 'required|integer|exists:semesters,id', 'section_name' => 'required|string|max:20', 'year_level' => 'required|integer|min:1|max:20', 'capacity' => 'required|integer|min:1|max:65535', 'adviser_id' => 'nullable|integer|exists:professors,id', 'status' => ['required', Rule::in(['open', 'closed'])], 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer']);
        foreach (['course_id', 'academic_year_id', 'semester_id', 'year_level', 'capacity', 'version', 'adviser_id'] as $key) {
            if (isset($in[$key])) {
                $in[$key] = (int) $in[$key];
            }
        }

        return $this->ok($this->scheduling->section($r->user(), $in, $id), $id ? 200 : 201);
    }

    public function schedules(Request $r)
    {
        $this->staff($r);
        $in = $r->validate(['section_id' => 'required|integer|exists:sections,id', 'search' => 'nullable|string|max:100', 'day' => ['nullable', Rule::in(EnrollmentSchedulingService::DAYS)]]);
        $section = Section::with(['course', 'academicYear', 'semester'])->findOrFail($in['section_id']);
        $q = SectionSubject::with(['subject', 'professor.user.profile'])->where('section_id', $section->id);
        if (! empty($in['day'])) {
            $q->where('day', $in['day']);
        }if (! empty($in['search'])) {
            $q->whereHas('subject', fn ($s) => $s->where('subject_name', 'like', '%'.$in['search'].'%')->orWhere('subject_code', 'like', '%'.$in['search'].'%'));
        }

        return $this->ok(['section' => $section, 'schedules' => $q->orderBy('day')->orderBy('start_time')->paginate(100), 'subjects' => Subject::whereHas('curriculum', fn ($c) => $c->where('course_id', $section->course_id)->where('status', 'active')->where('effective_year', '<=', now()->year))->where('semester_id', $section->semester_id)->where('year_level', $section->year_level)->where('status', 'active')->orderBy('subject_code')->limit(500)->get(), 'days' => EnrollmentSchedulingService::DAYS]);
    }

    public function saveSchedule(Request $r, ?int $id = null)
    {
        $this->staff($r);
        $in = $r->validate(['section_id' => 'required|integer|exists:sections,id', 'subject_id' => 'required|integer|exists:subjects,id', 'professor_id' => 'required|integer|exists:professors,id', 'day' => ['required', Rule::in(EnrollmentSchedulingService::DAYS)], 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'room' => 'required|string|max:50', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer']);
        foreach (['section_id', 'subject_id', 'professor_id', 'version'] as $key) {
            if (isset($in[$key])) {
                $in[$key] = (int) $in[$key];
            }
        }$in['start_time'] .= ':00';
        $in['end_time'] .= ':00';

        return $this->ok($this->scheduling->schedule($r->user(), $in, $id), $id ? 200 : 201);
    }

    public function deleteSchedule(Request $r, int $id)
    {
        $this->staff($r);
        $in = $r->validate(['version' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        $in['version'] = (int) $in['version'];
        $this->scheduling->schedule($r->user(), $in, $id, true);

        return $this->ok(null);
    }

    public function application(Request $r, int $id)
    {
        $a = $this->academic->application($r->user(), $id)->load(['student.userProfile', 'course', 'curriculum', 'period.academicYear', 'period.semester', 'section', 'subjects']);
        $sections = $this->counts(Section::with(['academicYear', 'semester'])->where('course_id', $a->course_id)->where('year_level', $a->year_level)->where('academic_year_id', $a->academic_year_id)->where('semester_id', $a->semester_id)->where('status', 'open'))->orderBy('section_name')->paginate(50);

        return $this->ok(['application' => $a, 'load' => $this->loads->proposal($a), 'sections' => $sections, 'total_units' => $a->subjects->sum('units')]);
    }

    public function change(Request $r, int $id, string $action)
    {
        $this->academic->application($r->user(), $id);
        $rules = ['version' => 'required|integer|min:1'];
        if ($action === 'subjects') {
            $rules['subject_ids'] = 'present|array|max:100';
        }if ($action === 'subjects') {
            $rules['subject_ids.*'] = 'required|integer|distinct';
        }if ($action === 'assign') {
            $rules['section_id'] = 'required|integer|exists:sections,id';
        }if ($action === 'finalize') {
            $rules['confirmed'] = 'required|accepted';
        }
        $in = $r->validate($rules);
        $in['version'] = (int) $in['version'];
        if (isset($in['section_id'])) {
            $in['section_id'] = (int) $in['section_id'];
        }if (isset($in['subject_ids'])) {
            $in['subject_ids'] = array_map('intval', $in['subject_ids']);
        }

        return $this->ok($this->academic->change($r->user(), $id, $action, $in));
    }

    public function records(Request $r)
    {
        $staff = $this->isStaff($r);
        $actor = EnrollmentAccess::require($r->user(), $staff);
        $in = $r->validate(['search' => 'nullable|string|max:100', 'course_id' => 'nullable|integer', 'section_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer', 'semester_id' => 'nullable|integer', 'classification' => ['nullable', Rule::in(['regular', 'irregular', 'transferee', 'returnee'])], 'status' => ['nullable', Rule::in(['pending', 'enrolled', 'completed', 'cancelled'])], 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'student_number', 'name', 'classification'])]]);
        $q = Enrollment::with(['student.userProfile', 'student.course', 'section.course', 'academicYear', 'semester', 'application'])->when(! $staff, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('user_id', $actor->id)));
        foreach (['section_id', 'academic_year_id', 'semester_id', 'status'] as $key) {
            if (! empty($in[$key])) {
                $q->where('enrollments.'.$key, $in[$key]);
            }
        }
        if (! empty($in['course_id'])) {
            $q->whereHas('section', fn ($s) => $s->where('course_id', $in['course_id']));
        }if (! empty($in['classification'])) {
            $q->whereHas('application', fn ($a) => $a->where('classification', $in['classification']));
        }
        foreach (preg_split('/\s+/', trim($in['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part).'%';
            $q->whereHas('student', fn ($s) => $s->where(fn ($s) => $s->whereRaw("student_number LIKE ? ESCAPE '!'", [$term])->orWhereHas('userProfile', fn ($p) => $p->whereRaw("first_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$term]))));
        }
        $sort = $in['sort'] ?? 'newest';
        if (in_array($sort, ['name', 'student_number'])) {
            $q->join('students as sort_students', 'sort_students.id', '=', 'enrollments.student_id')->select('enrollments.*');
            if ($sort === 'name') {
                $q->join('user_profiles as sort_profiles', 'sort_profiles.id', '=', 'sort_students.user_profile_id')->orderBy('sort_profiles.last_name')->orderBy('sort_profiles.first_name');
            } else {
                $q->orderBy('sort_students.student_number');
            }
        }
        if ($sort === 'classification') {
            $q->leftJoin('enrollment_applications as sort_app', 'sort_app.enrollment_id', '=', 'enrollments.id')->select('enrollments.*')->orderBy('sort_app.classification');
        }

        return $this->ok($q->orderBy('enrollments.id', $sort === 'oldest' ? 'asc' : 'desc')->paginate(25));
    }

    public function record(Request $r, int $id)
    {
        $staff = $this->isStaff($r);
        $actor = EnrollmentAccess::require($r->user(), $staff);
        $e = Enrollment::when(! $staff, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('user_id', $actor->id)))->with(['student.userProfile', 'student.course', 'section', 'academicYear', 'semester', 'enrollmentSubjects.subject', 'application.course'])->findOrFail($id);
        abort_unless(in_array($e->status, ['enrolled', 'completed']), 409, 'COR is available only for finalized academic enrollment.');
        $schedules = SectionSubject::with('professor.user.profile')->where('section_id', $e->section_id)->whereIn('subject_id', $e->enrollmentSubjects->pluck('subject_id'))->get()->keyBy('subject_id');
        $profile = $e->student->userProfile;

        return $this->ok(['id' => $e->id, 'student_number' => $e->student->student_number, 'name' => trim($profile->first_name.' '.$profile->last_name), 'course' => $e->application?->course?->course_name ?? $e->student->course?->course_name, 'year_level' => $e->application?->year_level ?? $e->section->year_level, 'academic_year' => $e->academicYear->school_year, 'semester' => $e->semester->semester_name, 'section' => $e->section->section_name, 'classification' => $e->application?->classification, 'status' => $e->status, 'enrollment_date' => $e->enrollment_date,
            'subjects' => $e->enrollmentSubjects->map(fn ($es) => ['id' => $es->subject_id, 'subject_code' => $es->subject->subject_code, 'subject_name' => $es->subject->subject_name, 'units' => (float) $es->subject->units, 'lecture_hours' => $es->subject->lecture_hours, 'laboratory_hours' => $es->subject->laboratory_hours, 'schedule' => $schedules->get($es->subject_id)])->values(), 'total_units' => $e->enrollmentSubjects->sum(fn ($es) => (float) $es->subject->units)]);
    }

    public function professor(Request $r, ?int $section = null)
    {
        $actor = $r->user()->fresh('role');
        abort_unless($actor->status === 'active' && $actor->role?->role_name === Role::PROFESSOR, 403, 'Active Professor access required.');
        $p = Professor::where('user_id', $actor->id)->where('status', 'active')->firstOrFail();
        $q = Section::where(fn ($q) => $q->where('adviser_id', $p->id)->orWhereHas('schedules', fn ($s) => $s->where('professor_id', $p->id)));
        if ($section) {
            $s = $q->findOrFail($section);

            return $this->ok(Enrollment::with('student.userProfile')->where('section_id', $s->id)->where('status', 'enrolled')->latest('id')->paginate(25));
        }

        return $this->ok($q->with(['course', 'academicYear', 'semester', 'schedules' => fn ($s) => $s->where('professor_id', $p->id)->with('subject')])->orderByDesc('id')->paginate(25));
    }

    public function notices(Request $r)
    {
        $actor = EnrollmentAccess::require($r->user());

        return $this->ok($actor->notifications()->where('type', EnrollmentNotice::class)->latest()->paginate(20));
    }

    public function readNotice(Request $r, string $id)
    {
        $actor = EnrollmentAccess::require($r->user());
        $n = $actor->notifications()->where('type', EnrollmentNotice::class)->findOrFail($id);
        $n->markAsRead();

        return $this->ok(null);
    }
}

<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Notifications\EnrollmentNotice;
use App\Services\AccountProvisioningService;
use App\Services\Enrollment\EnrollmentAcademicService;
use App\Services\Enrollment\EnrollmentAccess;
use App\Services\Enrollment\EnrollmentOvrfService;
use App\Services\Enrollment\EnrollmentSchedulingService;
use App\Services\Enrollment\EnrollmentSubjectManagementService;
use App\Services\Enrollment\EnrollmentSubjectPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnrollmentAcademicController extends Controller
{
    public function __construct(private EnrollmentAcademicService $academic, private EnrollmentSubjectPolicy $loads, private EnrollmentSchedulingService $scheduling, private EnrollmentSubjectManagementService $subjectManagement, private AccountProvisioningService $accounts, private EnrollmentOvrfService $ovrf) {}

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

        return $this->ok(['courses' => Course::where('status', 'active')->orderBy('course_name')->limit(300)->get(['id', 'course_name', 'years']), 'curriculums' => Curriculum::where('status', 'active')->orderByDesc('effective_year')->get(['id', 'course_id', 'curriculum_code', 'curriculum_name', 'effective_year']), 'academic_years' => AcademicYear::where('status', 'active')->latest('id')->limit(50)->get(), 'semesters' => Semester::where('status', 'active')->get(), 'professors' => Professor::with('user.profile')->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')->whereHas('role', fn ($r) => $r->where('role_name', Role::PROFESSOR)))->orderBy('id')->paginate(100)]);
    }

    public function catalog(Request $r)
    {
        $actor = EnrollmentAccess::require($r->user());
        abort_unless($actor->fresh('role')->role?->role_name === Role::STUDENT, 403, 'The academic program catalog is available to active Students.');

        $academicYear = AcademicYear::where('status', 'active')->orderByDesc('start_date')->orderByDesc('id')->first();
        $courses = Course::query()
            ->where('status', 'active')
            ->with([
                'department:id,department_name',
                'curriculums' => fn ($query) => $query->where('status', 'active')->orderByDesc('effective_year')->orderBy('curriculum_code'),
                'curriculums.subjects' => fn ($query) => $query->where('status', 'active')->orderBy('year_level')->orderBy('semester_id')->orderBy('subject_code'),
                'curriculums.subjects.semester:id,semester_name,semester_order,status',
            ])
            ->orderBy('course_name')
            ->limit(300)
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'code' => $course->course_code,
                'title' => $course->course_name,
                'duration_years' => $course->years,
                'department' => $course->department?->department_name,
                'curricula' => $course->curriculums->map(fn (Curriculum $curriculum) => [
                    'id' => $curriculum->id,
                    'code' => $curriculum->curriculum_code,
                    'name' => $curriculum->curriculum_name,
                    'effective_year' => $curriculum->effective_year,
                    'subjects' => $curriculum->subjects->map(fn (Subject $subject) => [
                        'id' => $subject->id,
                        'code' => $subject->subject_code,
                        'name' => $subject->subject_name,
                        'year_level' => $subject->year_level,
                        'semester' => $subject->semester?->semester_name,
                        'semester_order' => $subject->semester?->semester_order,
                        'units' => (float) $subject->units,
                    ])->values(),
                ])->values(),
            ])->values();

        return $this->ok([
            'academic_year' => $academicYear?->only(['id', 'school_year', 'start_date', 'end_date']),
            'programs' => $courses,
            'metadata' => [
                'descriptions_available' => false,
                'capacity_available' => false,
                'study_mode_available' => false,
            ],
        ]);
    }

    public function sections(Request $r)
    {
        $this->staff($r);
        $in = $r->validate(['search' => 'nullable|string|max:100', 'course_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer', 'semester_id' => 'nullable|integer', 'status' => ['nullable', Rule::in(['open', 'closed'])]]);
        $q = Section::with(['course', 'curriculum', 'academicYear', 'semester']);
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
        $in = $r->validate(['course_id' => 'required|integer|exists:courses,id', 'curriculum_id' => 'nullable|integer|exists:curriculums,id', 'academic_year_id' => 'required|integer|exists:academic_years,id', 'semester_id' => 'required|integer|exists:semesters,id', 'section_name' => 'required|string|max:20', 'year_level' => 'required|integer|min:1|max:20', 'capacity' => 'required|integer|min:1|max:65535', 'adviser_id' => 'nullable|integer|exists:professors,id', 'status' => ['required', Rule::in(['open', 'closed'])], 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer']);
        foreach (['course_id', 'curriculum_id', 'academic_year_id', 'semester_id', 'year_level', 'capacity', 'version', 'adviser_id'] as $key) {
            if (isset($in[$key])) {
                $in[$key] = (int) $in[$key];
            }
        }

        return $this->ok($this->scheduling->section($r->user(), $in, $id), $id ? 200 : 201);
    }

    public function subjects(Request $r)
    {
        $this->staff($r);
        $in = $r->validate(['search' => 'nullable|string|max:100', 'course_id' => 'nullable|integer|exists:courses,id', 'curriculum_id' => 'nullable|integer|exists:curriculums,id', 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = Subject::with(['curriculum.course', 'semester', 'prerequisite'])
            ->withCount(['sectionSubjects', 'enrollmentSubjects']);
        if (! empty($in['course_id'])) {
            $query->whereHas('curriculum', fn ($curriculum) => $curriculum->where('course_id', $in['course_id']));
        }
        foreach (['curriculum_id', 'status'] as $key) {
            if (! empty($in[$key])) {
                $query->where($key, $in[$key]);
            }
        }
        if (! empty($in['search'])) {
            $search = '%'.$in['search'].'%';
            $query->where(fn ($subject) => $subject->where('subject_code', 'like', $search)->orWhere('subject_name', 'like', $search));
        }

        return $this->ok([
            'subjects' => $query->orderBy('subject_code')->paginate(50),
            'courses' => Course::orderBy('course_code')->get(['id', 'course_code', 'course_name', 'years', 'status']),
            'curriculums' => Curriculum::with('course:id,course_code,course_name,status')->orderByDesc('effective_year')->orderBy('curriculum_code')->get(),
            'semesters' => Semester::orderBy('semester_order')->get(),
        ]);
    }

    public function saveSubject(Request $r, ?int $id = null)
    {
        $this->staff($r);
        $r->merge([
            'subject_code' => EnrollmentSubjectManagementService::normalizeCode((string) $r->input('subject_code')),
            'subject_name' => trim((string) $r->input('subject_name')),
        ]);
        $in = $r->validate([
            'curriculum_id' => 'required|integer|exists:curriculums,id',
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')->where('status', 'active')],
            'subject_code' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]+$/'],
            'subject_name' => 'required|string|max:150',
            'year_level' => 'required|integer|min:1|max:20',
            'units' => 'required|numeric|min:0|max:999.9',
            'lecture_hours' => 'required|integer|min:0|max:40',
            'laboratory_hours' => 'required|integer|min:0|max:40',
            'prerequisite_subject_id' => 'nullable|integer|exists:subjects,id',
            'status' => $id ? 'prohibited' : ['required', Rule::in(['active', 'inactive'])],
        ]);
        foreach (['curriculum_id', 'semester_id', 'year_level', 'lecture_hours', 'laboratory_hours', 'prerequisite_subject_id'] as $key) {
            if (isset($in[$key])) {
                $in[$key] = (int) $in[$key];
            }
        }
        $in['units'] = (float) $in['units'];

        return $this->ok($this->subjectManagement->save($r->user(), $in, $id), $id ? 200 : 201);
    }

    public function subjectStatus(Request $r, int $id, string $action)
    {
        $this->staff($r);
        abort_unless(in_array($action, ['archive', 'reactivate'], true), 404);

        return $this->ok($this->subjectManagement->setStatus($r->user(), $id, $action === 'archive' ? 'inactive' : 'active'));
    }

    public function subjectProfessors(Request $r, Subject $subject)
    {
        $this->staff($r);
        abort_unless($r->user()->fresh('role')->role?->role_name === Role::REGISTRAR_STAFF, 403, 'Only Registrar Staff may manage Professor subject associations.');
        $assignedIds = $subject->professors()->pluck('professors.id')->map(fn (int $id): int => $id)->sort()->values();
        $professors = Professor::query()->with(['user.profile', 'department'])
            ->where(function ($query) use ($assignedIds): void {
                $query->where('status', 'active')->orWhereIn('id', $assignedIds);
            })
            ->whereHas('user', fn ($query) => $query->whereHas('role', fn ($role) => $role->where('role_name', Role::PROFESSOR)))
            ->orderBy('employee_number')->get()->map(fn (Professor $professor): array => [
                'id' => $professor->id,
                'employee_number' => $professor->employee_number,
                'status' => $professor->status,
                'account_status' => $professor->user?->status,
                'first_name' => $professor->user?->profile?->first_name,
                'middle_name' => $professor->user?->profile?->middle_name,
                'last_name' => $professor->user?->profile?->last_name,
                'department_code' => $professor->department?->department_code,
                'department_name' => $professor->department?->department_name,
            ]);

        return $this->ok([
            'subject' => $subject->only(['id', 'subject_code', 'subject_name', 'status']),
            'assigned_professor_ids' => $assignedIds->all(),
            'professors' => $professors,
        ]);
    }

    public function syncSubjectProfessors(Request $r, Subject $subject)
    {
        $this->staff($r);
        abort_unless($r->user()->fresh('role')->role?->role_name === Role::REGISTRAR_STAFF, 403, 'Only Registrar Staff may manage Professor subject associations.');
        $input = $r->validate([
            'professor_ids' => ['present', 'array', 'max:500'],
            'professor_ids.*' => ['required', 'integer', 'distinct', 'exists:professors,id'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $subject = $this->accounts->syncSubjectProfessors($r->user(), $subject, array_map('intval', $input['professor_ids']));

        return $this->ok(['assigned_professor_ids' => $subject->professors->pluck('id')->sort()->values()->all()]);
    }

    public function schedules(Request $r)
    {
        $this->staff($r);
        $in = $r->validate(['section_id' => 'required|integer|exists:sections,id', 'search' => 'nullable|string|max:100', 'day' => ['nullable', Rule::in(EnrollmentSchedulingService::DAYS)]]);
        $section = Section::with(['course', 'curriculum', 'academicYear', 'semester'])->findOrFail($in['section_id']);
        $q = SectionSubject::with(['subject', 'professor.user.profile', 'meetings'])->where('section_id', $section->id);
        if (! empty($in['day'])) {
            $q->whereHas('meetings', fn ($meeting) => $meeting->where('day', $in['day']));
        }if (! empty($in['search'])) {
            $q->whereHas('subject', fn ($s) => $s->where('subject_name', 'like', '%'.$in['search'].'%')->orWhere('subject_code', 'like', '%'.$in['search'].'%'));
        }

        $curricula = Curriculum::where('course_id', $section->course_id)->where('status', 'active')
            ->when($section->curriculum_id, fn ($query) => $query->whereKey($section->curriculum_id))
            ->orderBy('id')->limit(2)->get();
        $curriculum = $curricula->count() === 1 ? $curricula->first() : null;
        $configurationIssue = $curriculum ? null : 'This Section has no unambiguous active curriculum. Edit the Section and choose its curriculum.';

        return $this->ok(['section' => $section, 'curriculum' => $curriculum, 'configuration_issue' => $configurationIssue, 'schedules' => $q->orderBy('id')->paginate(100), 'subjects' => $curriculum ? Subject::with('professors:id')->where('curriculum_id', $curriculum->id)->where('semester_id', $section->semester_id)->where('year_level', $section->year_level)->where('status', 'active')->orderBy('subject_code')->limit(500)->get() : [], 'days' => EnrollmentSchedulingService::DAYS]);
    }

    public function saveSchedule(Request $r, ?int $id = null)
    {
        $this->staff($r);
        $batch = $r->has('meetings');
        $in = $r->validate([
            'assignment_id' => $id ? 'prohibited' : 'nullable|integer|exists:section_subjects,id',
            'section_id' => 'required|integer|exists:sections,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'professor_id' => 'required|integer|exists:professors,id',
            'day' => $batch ? 'prohibited' : ['required', Rule::in(EnrollmentSchedulingService::DAYS)],
            'start_time' => $batch ? 'prohibited' : 'required|date_format:H:i',
            'end_time' => $batch ? 'prohibited' : 'required|date_format:H:i|after:start_time',
            'meetings' => $batch ? 'required|array|min:1|max:7' : 'prohibited',
            'meetings.*.day' => $batch ? ['required', 'distinct', Rule::in(EnrollmentSchedulingService::DAYS)] : 'prohibited',
            'meetings.*.start_time' => $batch ? 'required|date_format:H:i' : 'prohibited',
            'meetings.*.end_time' => $batch ? 'required|date_format:H:i|after:meetings.*.start_time' : 'prohibited',
            'room' => 'required|string|max:50',
            'version' => $id ? 'required|integer|min:1' : 'sometimes|integer',
        ]);
        foreach (['assignment_id', 'section_id', 'subject_id', 'professor_id', 'version'] as $key) {
            if (isset($in[$key])) {
                $in[$key] = (int) $in[$key];
            }
        }
        if ($batch) {
            $in['meetings'] = array_map(fn (array $meeting): array => array_replace($meeting, [
                'start_time' => $meeting['start_time'].':00',
                'end_time' => $meeting['end_time'].':00',
            ]), $in['meetings']);
        } else {
            $in['start_time'] .= ':00';
            $in['end_time'] .= ':00';
        }

        return $this->ok($this->scheduling->schedule($r->user(), $in, $id), $id ? 200 : 201);
    }

    public function scheduleAvailability(Request $r)
    {
        $this->staff($r);
        $in = $r->validate([
            'schedule_id' => 'nullable|integer|exists:section_subjects,id',
            'section_id' => 'required|integer|exists:sections,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'professor_id' => 'required|integer|exists:professors,id',
            'meetings' => 'required|array|min:1|max:7',
            'meetings.*.day' => ['required', Rule::in(EnrollmentSchedulingService::DAYS)],
            'meetings.*.start_time' => 'required|date_format:H:i',
            'meetings.*.end_time' => 'required|date_format:H:i|after:meetings.*.start_time',
            'room' => 'required|string|max:50',
        ]);
        foreach (['schedule_id', 'section_id', 'subject_id', 'professor_id'] as $key) {
            if (isset($in[$key])) {
                $in[$key] = (int) $in[$key];
            }
        }
        $in['meetings'] = array_map(fn (array $meeting): array => array_replace($meeting, [
            'start_time' => $meeting['start_time'].':00',
            'end_time' => $meeting['end_time'].':00',
        ]), $in['meetings']);

        return $this->ok($this->scheduling->availability($r->user(), $in, $in['schedule_id'] ?? null));
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
        $a = $this->academic->application($r->user(), $id)->load(['student.userProfile', 'owner.profile', 'admissionApplicant', 'course', 'curriculum', 'period.academicYear', 'period.semester', 'section', 'subjects']);
        $sections = $this->counts(Section::with(['academicYear', 'semester'])->where('course_id', $a->course_id)->where('year_level', $a->year_level)->where('academic_year_id', $a->academic_year_id)->where('semester_id', $a->semester_id)->where('status', 'open'))->orderBy('section_name')->paginate(50);

        $load = $this->loads->proposal($a);
        $assignments = $a->section_id
            ? SectionSubject::with('meetings')
                ->where('section_id', $a->section_id)
                ->whereIn('subject_id', collect($load['candidates'])->pluck('id'))
                ->get()
                ->keyBy('subject_id')
            : collect();
        $load['candidates'] = collect($load['candidates'])->map(function (array $subject) use ($assignments): array {
            $assignment = $assignments->get($subject['id']);
            $scheduled = $assignment && ($assignment->meetings->isNotEmpty()
                || ($assignment->day && $assignment->start_time && $assignment->end_time && $assignment->room));

            return $subject + ['schedule_status' => $scheduled ? 'scheduled' : 'pending'];
        })->all();

        return $this->ok(['application' => $a, 'load' => $load, 'sections' => $sections, 'total_units' => $a->subjects->sum('units')]);
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
        $schedules = SectionSubject::with(['professor.user.profile', 'meetings'])->where('section_id', $e->section_id)->whereIn('subject_id', $e->enrollmentSubjects->pluck('subject_id'))->get()->keyBy('subject_id');
        $profile = $e->student->userProfile;

        return $this->ok(['id' => $e->id, 'student_number' => $e->student->student_number, 'name' => trim($profile->first_name.' '.$profile->last_name), 'course' => $e->application?->course?->course_name ?? $e->student->course?->course_name, 'year_level' => $e->application?->year_level ?? $e->section->year_level, 'academic_year' => $e->academicYear->school_year, 'semester' => $e->semester->semester_name, 'section' => $e->section->section_name, 'classification' => $e->application?->classification, 'status' => $e->status, 'enrollment_date' => $e->enrollment_date,
            'subjects' => $e->enrollmentSubjects->map(function ($es) use ($schedules): array {
                $schedule = $schedules->get($es->subject_id);
                $scheduled = $schedule && ($schedule->meetings->isNotEmpty() || ($schedule->day && $schedule->start_time && $schedule->end_time && $schedule->room));

                return ['id' => $es->subject_id, 'subject_code' => $es->subject->subject_code, 'subject_name' => $es->subject->subject_name, 'units' => (float) $es->subject->units, 'lecture_hours' => $es->subject->lecture_hours, 'laboratory_hours' => $es->subject->laboratory_hours, 'schedule_status' => $scheduled ? 'scheduled' : 'pending', 'schedule' => $schedule];
            })->values(), 'total_units' => $e->enrollmentSubjects->sum(fn ($es) => (float) $es->subject->units)]);
    }

    public function ovrf(Request $request, int $id)
    {
        $input = $request->validate([
            'disposition' => ['nullable', Rule::in(['inline', 'attachment'])],
        ]);

        return $this->ovrf->render($request->user(), $id, $input['disposition'] ?? 'inline');
    }

    public function professor(Request $r, ?int $section = null)
    {
        $actor = $r->user()->fresh('role');
        abort_unless($actor->status === 'active' && $actor->role?->role_name === Role::PROFESSOR, 403, 'Active Professor access required.');
        $p = Professor::where('user_id', $actor->id)->where('status', 'active')->firstOrFail();
        $q = Section::whereHas('schedules', fn ($s) => $s->where('professor_id', $p->id));
        if ($section) {
            $s = $q->findOrFail($section);

            $subjectIds = $s->schedules()->where('professor_id', $p->id)->pluck('subject_id');

            return $this->ok(Enrollment::with('student.userProfile')
                ->where('section_id', $s->id)
                ->where('status', 'enrolled')
                ->whereHas('enrollmentSubjects', fn ($subjects) => $subjects
                    ->whereIn('subject_id', $subjectIds)
                    ->whereIn('subject_status', ['enrolled', 'completed']))
                ->latest('id')
                ->paginate(25));
        }

        return $this->ok($q->with(['course', 'academicYear', 'semester', 'schedules' => fn ($s) => $s->where('professor_id', $p->id)->with(['subject', 'meetings'])])->orderByDesc('id')->paginate(25));
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

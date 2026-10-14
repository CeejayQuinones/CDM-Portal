<?php

namespace App\Services\StudentRecords;

use App\Models\AcademicYear;
use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentRecordReportService
{
    private const TITLES = [
        'master-list' => 'Student Master List',
        'academic-summary' => 'Student Academic Summary',
        'physical-records' => 'Physical Records Status',
        'activity-summary' => 'Student Record Activity Summary',
    ];

    public function __construct(private readonly StudentService $students) {}

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'academic_years' => AcademicYear::query()->orderByDesc('start_date')->get(['id', 'school_year', 'status']),
            'semesters' => Semester::query()->orderBy('semester_order')->get(['id', 'semester_name', 'status']),
            'courses' => Course::query()->orderBy('course_code')->get(['id', 'course_code', 'course_name']),
            'sections' => Section::query()->with(['course:id,course_code', 'academicYear:id,school_year', 'semester:id,semester_name'])
                ->orderBy('section_name')->get(['id', 'course_id', 'academic_year_id', 'semester_id', 'year_level', 'section_name']),
            'cabinets' => Cabinet::query()->orderBy('cabinet_code')->get(['id', 'cabinet_code', 'description']),
            'staff' => User::query()->whereHas('role', fn (Builder $query) => $query->whereIn('role_name', [Role::REGISTRAR_STAFF, Role::ADMIN]))
                ->with(['profile:id,user_id,first_name,last_name', 'role:id,role_name'])
                ->orderBy('username')->get(['id', 'username', 'role_id'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
                    'role' => $user->role?->role_name,
                ])->values(),
            'action_types' => $this->activityActionTypes(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function report(string $type, array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        $paginator = $type === 'activity-summary'
            ? $this->activityReport($filters, $perPage, $page)
            : $this->studentReport($type, $filters, $perPage, $page);

        return [
            'report_type' => $type,
            'title' => self::TITLES[$type],
            'generated_at' => now()->toIso8601String(),
            'applied_filters' => $this->filterLabels($filters),
            'summary' => $type === 'activity-summary'
                ? ['total_activities' => $paginator->total()]
                : $this->studentSummary($filters),
            'rows' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function exportRows(string $type, array $filters): \Generator
    {
        $page = 1;

        do {
            $paginator = $type === 'activity-summary'
                ? $this->activityReport($filters, 500, $page)
                : $this->studentReport($type, $filters, 500, $page);

            foreach ($paginator->items() as $row) {
                yield $this->csvRow($type, $row);
            }

            $page++;
        } while ($paginator->hasMorePages());
    }

    /** @return list<string> */
    public function csvHeaders(string $type): array
    {
        return match ($type) {
            'master-list' => ['Student Number', 'Student Name', 'Program', 'Year Level', 'Section', 'Academic Year', 'Semester', 'Enrollment Status'],
            'academic-summary' => ['Student Number', 'Student Name', 'Program', 'Academic Year', 'Semester', 'Section', 'Enrollment Status', 'Enrolled Subjects', 'Published Subject Results'],
            'physical-records' => ['Student Number', 'Student Name', 'Program', 'Physical Record Status', 'Cabinet', 'Location', 'Document Count', 'Missing/Unavailable Documents'],
            'activity-summary' => ['Date/Time', 'Student Number', 'Student Name', 'Action', 'Source', 'Staff User', 'Staff Role'],
        };
    }

    /** @param array<string, mixed> $filters */
    public function filename(string $type, array $filters, string $extension = 'csv'): string
    {
        $term = null;
        if ($type === 'academic-summary' && isset($filters['academic_year_id'])) {
            $year = AcademicYear::find($filters['academic_year_id'])?->school_year;
            $semester = isset($filters['semester_id']) ? Semester::find($filters['semester_id'])?->semester_name : null;
            $term = Str::slug(trim($year.' '.$semester));
        }

        return $type.($term ? '-'.$term : '').'-'.now()->format('Y-m-d').'.'.$extension;
    }

    public function title(string $type): string
    {
        return self::TITLES[$type];
    }

    /** @param array<string, mixed> $filters */
    private function studentReport(string $type, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->students->withCurrentEnrollment($this->studentQuery($filters))
            ->with(['userProfile:id,user_id,first_name,middle_name,last_name,suffix', 'course:id,course_code,course_name'])
            ->withCount([
                'documents',
                'documents as unavailable_documents_count' => fn (Builder $documents) => $documents->where('availability_status', 'missing'),
            ]);

        if ($type === 'physical-records') {
            $query->with('physicalRecordLocation.cabinetSlot.cabinet');
        }

        $paginator = $query->orderBy('student_number')->paginate($perPage, ['*'], 'page', $page);
        $students = collect($paginator->items());
        $enrollments = $this->selectedEnrollments($students, $filters);
        $enrollmentIds = $enrollments->pluck('id')->filter()->values();
        $subjectCounts = $enrollmentIds->isEmpty() ? collect() : DB::table('enrollment_subjects')
            ->whereIn('enrollment_id', $enrollmentIds)->selectRaw('enrollment_id, COUNT(*) as total')
            ->groupBy('enrollment_id')->pluck('total', 'enrollment_id');
        $publishedCounts = $this->publishedCounts($students->pluck('id')->all(), $filters);

        return $paginator->through(function (Student $student) use ($type, $enrollments, $subjectCounts, $publishedCounts): array {
            $enrollment = $enrollments->get($student->id);
            $row = $this->studentIdentity($student, $enrollment);

            if ($type === 'academic-summary') {
                $row['enrolled_subject_count'] = (int) ($subjectCounts[$enrollment?->id] ?? 0);
                $row['published_subject_count'] = (int) ($publishedCounts[$student->id] ?? 0);
            }

            if ($type === 'physical-records') {
                $location = $student->physicalRecordLocation;
                $row += [
                    'physical_record_status' => $location ? 'Available' : 'Unavailable',
                    'cabinet' => $location?->cabinetSlot?->cabinet?->cabinet_code,
                    'location' => $location?->cabinetSlot?->slot_code,
                    'document_count' => (int) $student->documents_count,
                    'unavailable_document_count' => (int) $student->unavailable_documents_count,
                ];
            }

            return $row;
        });
    }

    /** @param array<string, mixed> $filters */
    private function studentQuery(array $filters): Builder
    {
        $query = Student::query();
        $this->applyStudentFilters($query, $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function applyStudentFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(function (Builder $nested) use ($search): void {
                $nested->where('student_number', 'like', "%{$search}%")
                    ->orWhereHas('userProfile', fn (Builder $profile) => $profile->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            }))
            ->when($filters['student_number'] ?? null, fn (Builder $q, string $number) => $q->where('student_number', 'like', "%{$number}%"))
            ->when($filters['course_id'] ?? null, fn (Builder $q, int $course) => $q->where('course_id', $course))
            ->when($filters['year_level'] ?? null, fn (Builder $q, int $year) => $q->where('year_level', $year));

        if ($this->hasEnrollmentFilters($filters)) {
            $query->whereHas('enrollments', fn (Builder $enrollment) => $this->applyEnrollmentFilters($enrollment, $filters));
        }

        if (($filters['availability'] ?? null) === 'available') {
            $query->whereHas('physicalRecordLocation');
        } elseif (($filters['availability'] ?? null) === 'unavailable') {
            $query->whereDoesntHave('physicalRecordLocation');
        }

        if (isset($filters['cabinet_id'])) {
            $query->whereHas('physicalRecordLocation.cabinetSlot', fn (Builder $slot) => $slot->where('cabinet_id', $filters['cabinet_id']));
        }
    }

    /** @param array<string, mixed> $filters */
    private function selectedEnrollments(Collection $students, array $filters): Collection
    {
        if ($students->isEmpty()) {
            return collect();
        }

        if (! $this->hasEnrollmentFilters($filters)) {
            return $students->mapWithKeys(fn (Student $student): array => $student->currentEnrollment
                ? [$student->id => $student->currentEnrollment]
                : []);
        }

        $query = Enrollment::query()->whereIn('student_id', $students->pluck('id'))
            ->with(['academicYear:id,school_year', 'semester:id,semester_name', 'section:id,section_name,year_level']);
        $this->applyEnrollmentFilters($query, $filters);

        return $query->orderByDesc('academic_year_id')->orderByDesc('semester_id')->orderByDesc('enrollment_date')->orderByDesc('id')
            ->get()->unique('student_id')->keyBy('student_id');
    }

    /** @param array<string, mixed> $filters */
    private function applyEnrollmentFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, int $id) => $q->where('academic_year_id', $id))
            ->when($filters['semester_id'] ?? null, fn (Builder $q, int $id) => $q->where('semester_id', $id))
            ->when($filters['section_id'] ?? null, fn (Builder $q, int $id) => $q->where('section_id', $id));
    }

    /** @param array<string, mixed> $filters */
    private function hasEnrollmentFilters(array $filters): bool
    {
        return isset($filters['academic_year_id']) || isset($filters['semester_id']) || isset($filters['section_id']);
    }

    private function studentIdentity(Student $student, ?Enrollment $enrollment): array
    {
        $profile = $student->userProfile;

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->middle_name, $profile?->last_name, $profile?->suffix]))),
            'program' => $student->course?->course_code,
            'program_name' => $student->course?->course_name,
            'year_level' => $enrollment?->section?->year_level ?? $student->year_level,
            'section' => $enrollment?->section?->section_name,
            'academic_year' => $enrollment?->academicYear?->school_year,
            'semester' => $enrollment?->semester?->semester_name,
            'enrollment_status' => $enrollment?->status,
        ];
    }

    /** @param list<int> $studentIds @param array<string, mixed> $filters */
    private function publishedCounts(array $studentIds, array $filters): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return DB::table('grade_submission_students as published_students')
            ->join('grade_submission_attempts as published_attempts', 'published_attempts.id', '=', 'published_students.grade_submission_attempt_id')
            ->join('grade_sheets as published_sheets', 'published_sheets.id', '=', 'published_attempts.grade_sheet_id')
            ->join('enrollment_subjects as published_subjects', 'published_subjects.id', '=', 'published_students.enrollment_subject_id')
            ->join('enrollments as published_enrollments', 'published_enrollments.id', '=', 'published_subjects.enrollment_id')
            ->whereIn('published_students.student_id', $studentIds)
            ->where('published_sheets.status', 'published')->whereNotNull('published_sheets.published_at')
            ->when($filters['academic_year_id'] ?? null, fn (QueryBuilder $q, int $id) => $q->where('published_enrollments.academic_year_id', $id))
            ->when($filters['semester_id'] ?? null, fn (QueryBuilder $q, int $id) => $q->where('published_enrollments.semester_id', $id))
            ->selectRaw('published_students.student_id, COUNT(DISTINCT published_students.enrollment_subject_id) as total')
            ->groupBy('published_students.student_id')->pluck('total', 'published_students.student_id');
    }

    /** @param array<string, mixed> $filters */
    private function studentSummary(array $filters): array
    {
        $base = $this->studentQuery($filters);
        $total = (clone $base)->count();

        return [
            'total_students' => $total,
            'currently_enrolled' => (clone $base)->whereHas('enrollments', function (Builder $query) use ($filters): void {
                $this->applyEnrollmentFilters($query, $filters);
                $query->where('status', 'enrolled');
            })->count(),
            'physical_records_available' => (clone $base)->whereHas('physicalRecordLocation')->count(),
            'physical_records_unavailable' => (clone $base)->whereDoesntHave('physicalRecordLocation')->count(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function activityReport(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $enrollment = DB::table('enrollment_workflow_events as events')
            ->join('enrollment_applications as applications', fn ($join) => $join->on('applications.id', '=', 'events.subject_id')->where('events.subject_type', 'application'))
            ->join('students', 'students.id', '=', 'applications.student_id')
            ->join('user_profiles as student_profiles', 'student_profiles.id', '=', 'students.user_profile_id')
            ->leftJoin('user_profiles as actor_profiles', 'actor_profiles.user_id', '=', 'events.actor_user_id')
            ->selectRaw("events.id as activity_id, 'Enrollment' as source, events.action as action_type, events.created_at as activity_at, students.id as student_id, students.student_number, student_profiles.first_name, student_profiles.last_name, events.actor_user_id, actor_profiles.first_name as actor_first_name, actor_profiles.last_name as actor_last_name, events.actor_role");

        $documents = DB::table('document_request_status_changes as changes')
            ->join('document_requests as requests', 'requests.id', '=', 'changes.document_request_id')
            ->join('students', 'students.id', '=', 'requests.student_id')
            ->join('user_profiles as student_profiles', 'student_profiles.id', '=', 'students.user_profile_id')
            ->leftJoin('registrar_staff as staff', 'staff.id', '=', 'changes.registrar_staff_id')
            ->leftJoin('user_profiles as actor_profiles', 'actor_profiles.id', '=', 'staff.user_profile_id')
            ->selectRaw("changes.id as activity_id, 'Document Request' as source, changes.action as action_type, changes.created_at as activity_at, students.id as student_id, students.student_number, student_profiles.first_name, student_profiles.last_name, staff.user_id as actor_user_id, actor_profiles.first_name as actor_first_name, actor_profiles.last_name as actor_last_name, CASE WHEN staff.id IS NULL THEN changes.actor_type ELSE 'Registrar Staff' END as actor_role");

        $recordEvents = DB::table('student_record_audit_events as record_events')
            ->join('students', 'students.id', '=', 'record_events.student_id')
            ->join('user_profiles as student_profiles', 'student_profiles.id', '=', 'students.user_profile_id')
            ->join('users as actors', 'actors.id', '=', 'record_events.actor_user_id')
            ->join('roles as actor_roles', 'actor_roles.id', '=', 'actors.role_id')
            ->leftJoin('user_profiles as actor_profiles', 'actor_profiles.user_id', '=', 'actors.id')
            ->selectRaw("record_events.id as activity_id, 'Student Records' as source, record_events.action as action_type, record_events.created_at as activity_at, students.id as student_id, students.student_number, student_profiles.first_name, student_profiles.last_name, record_events.actor_user_id, actor_profiles.first_name as actor_first_name, actor_profiles.last_name as actor_last_name, actor_roles.role_name as actor_role");

        $query = DB::query()->fromSub($enrollment->unionAll($documents)->unionAll($recordEvents), 'activities');
        $query
            ->when($filters['search'] ?? null, fn (QueryBuilder $q, string $search) => $q->where(function (QueryBuilder $nested) use ($search): void {
                $nested->where('student_number', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->when($filters['student_id'] ?? null, fn (QueryBuilder $q, int $id) => $q->where('student_id', $id))
            ->when($filters['action_type'] ?? null, fn (QueryBuilder $q, string $action) => $q->where('action_type', $action))
            ->when($filters['staff_user_id'] ?? null, fn (QueryBuilder $q, int $id) => $q->where('actor_user_id', $id))
            ->when($filters['date_from'] ?? null, fn (QueryBuilder $q, string $date) => $q->whereDate('activity_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (QueryBuilder $q, string $date) => $q->whereDate('activity_at', '<=', $date));

        return $query->orderByDesc('activity_at')->orderByDesc('activity_id')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn ($row): array => [
                'activity_at' => $row->activity_at,
                'student_id' => (int) $row->student_id,
                'student_number' => $row->student_number,
                'student_name' => trim($row->first_name.' '.$row->last_name),
                'action_type' => $row->action_type,
                'source' => $row->source,
                'staff_user_id' => $row->actor_user_id ? (int) $row->actor_user_id : null,
                'staff_name' => trim(($row->actor_first_name ?? '').' '.($row->actor_last_name ?? '')) ?: null,
                'staff_role' => $row->actor_role,
            ]);
    }

    /** @return list<string> */
    private function activityActionTypes(): array
    {
        return collect()
            ->merge(DB::table('enrollment_workflow_events')->distinct()->orderBy('action')->pluck('action'))
            ->merge(DB::table('document_request_status_changes')->distinct()->orderBy('action')->pluck('action'))
            ->merge(DB::table('student_record_audit_events')->distinct()->orderBy('action')->pluck('action'))
            ->filter()->unique()->sort()->values()->all();
    }

    /** @param array<string, mixed> $filters @return array<string, string> */
    public function filterLabels(array $filters): array
    {
        $labels = [];
        foreach (['search', 'student_number', 'year_level', 'availability', 'action_type', 'date_from', 'date_to'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $labels[$key] = (string) $filters[$key];
            }
        }
        if (isset($filters['course_id'])) {
            $labels['program'] = Course::find($filters['course_id'])?->course_code ?? (string) $filters['course_id'];
        }
        if (isset($filters['academic_year_id'])) {
            $labels['academic_year'] = AcademicYear::find($filters['academic_year_id'])?->school_year ?? (string) $filters['academic_year_id'];
        }
        if (isset($filters['semester_id'])) {
            $labels['semester'] = Semester::find($filters['semester_id'])?->semester_name ?? (string) $filters['semester_id'];
        }
        if (isset($filters['section_id'])) {
            $labels['section'] = Section::find($filters['section_id'])?->section_name ?? (string) $filters['section_id'];
        }
        if (isset($filters['cabinet_id'])) {
            $labels['cabinet'] = Cabinet::find($filters['cabinet_id'])?->cabinet_code ?? (string) $filters['cabinet_id'];
        }

        return $labels;
    }

    /** @param array<string, mixed> $row @return list<string|int> */
    private function csvRow(string $type, array $row): array
    {
        $values = match ($type) {
            'master-list' => [$row['student_number'], $row['student_name'], $row['program'], $row['year_level'], $row['section'], $row['academic_year'], $row['semester'], $row['enrollment_status']],
            'academic-summary' => [$row['student_number'], $row['student_name'], $row['program'], $row['academic_year'], $row['semester'], $row['section'], $row['enrollment_status'], $row['enrolled_subject_count'], $row['published_subject_count']],
            'physical-records' => [$row['student_number'], $row['student_name'], $row['program'], $row['physical_record_status'], $row['cabinet'], $row['location'], $row['document_count'], $row['unavailable_document_count']],
            'activity-summary' => [$row['activity_at'], $row['student_number'], $row['student_name'], $row['action_type'], $row['source'], $row['staff_name'], $row['staff_role']],
        };

        return array_map(fn (mixed $value): string|int => is_string($value) && preg_match('/^[=+\-@]/', $value)
            ? "'".$value
            : ($value ?? ''), $values);
    }
}

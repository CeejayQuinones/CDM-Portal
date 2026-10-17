<?php

namespace App\Services;

use App\Http\Requests\BulkUpdateStudentsRequest;
use App\Models\Admission\AdmissionApplicant;
use App\Models\CabinetSlot;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\StudentRecords\StudentRecordAuditWriter;
use App\Services\StudentRecords\StudentRecordCompletenessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentService
{
    public function __construct(
        private readonly StudentRecordAuditWriter $audit,
        private readonly StudentRecordCompletenessService $completeness,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->withCurrentEnrollment(Student::query())
            ->with(['user', 'userProfile', 'course', 'curriculum'])
            ->addSelect(['has_admission_record' => AdmissionApplicant::query()
                ->selectRaw('1')
                ->whereColumn('admission_applicants.user_id', 'students.user_id')
                ->whereColumn('admission_applicants.converted_student_id', 'students.id')
                ->limit(1)])
            ->withExists('enrollmentApplications as has_enrollment_application')
            ->withExists('physicalRecordLocation as has_physical_record')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            ->when($filters['course'] ?? null, function ($query, string $course): void {
                $query->where(function ($courseQuery) use ($course): void {
                    $courseQuery->where('course_id', $course)
                        ->orWhereHas('course', fn ($relationQuery) => $relationQuery->where('course_code', $course));
                });
            })
            ->when($filters['year_level'] ?? null, fn ($query, int $yearLevel) => $query->where('year_level', $yearLevel))
            ->when($filters['section'] ?? null, function ($query, string $section): void {
                $query->where(function ($sectionQuery) use ($section): void {
                    $sectionQuery
                        ->whereHas('enrollments.section', fn ($relationQuery) => $relationQuery->where('section_name', 'like', "%{$section}%"))
                        ->orWhereHas('enrollmentApplications.section', fn ($relationQuery) => $relationQuery->where('section_name', 'like', "%{$section}%"));
                });
            })
            ->when($filters['student_status'] ?? null, fn ($query, string $status) => $query->where('student_status', $status))
            ->when(($filters['physical_record_status'] ?? null) === 'available', fn (Builder $query) => $query->whereHas('physicalRecordLocation'))
            ->when(($filters['physical_record_status'] ?? null) === 'unavailable', fn (Builder $query) => $query->whereDoesntHave('physicalRecordLocation'))
            ->when($filters['attention'] ?? null, fn (Builder $query, string $attention) => $this->applyAttention($query, $attention))
            ->tap(fn (Builder $query) => $this->applySort($query, $filters['sort'] ?? 'student_number', $filters['direction'] ?? 'asc'))
            ->paginate(15)
            ->withQueryString();
    }

    /** @return array{exact: bool, results: list<array<string, mixed>>} */
    public function quickSearch(string $search): array
    {
        $query = $this->withCurrentEnrollment(Student::query())
            ->with(['userProfile:id,first_name,middle_name,last_name,suffix', 'course:id,course_code']);
        $this->applySearch($query, $search);
        $results = $query->orderByRaw('CASE WHEN student_number = ? THEN 0 ELSE 1 END', [$search])
            ->orderBy('student_number')->limit(12)->get();

        return [
            'exact' => $results->count() === 1 && $results->first()->student_number === $search,
            'results' => $results->map(fn (Student $student): array => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name' => trim(collect([$student->userProfile?->first_name, $student->userProfile?->middle_name, $student->userProfile?->last_name, $student->userProfile?->suffix])->filter()->join(' ')),
                'program' => $student->course?->course_code,
                'section' => $student->currentEnrollmentApplication?->section?->section_name ?? $student->currentEnrollment?->section?->section_name,
            ])->all(),
        ];
    }

    public function find(int $studentId): Student
    {
        $student = $this->withCurrentEnrollment(Student::query(), true)
            ->with([
                'user.role',
                'userProfile',
                'course.department',
                'curriculum',
                'documents.documentType',
                'documents.aiAnalysis',
                'physicalRecordLocation.cabinetSlot.cabinet',
                'documentRequests' => fn ($requests) => $requests
                    ->with('documentType:id,document_name')
                    ->latest()
                    ->limit(10),
            ])
            ->findOrFail($studentId);
        $student->setAttribute('record_completeness', $this->completeness->evaluate($student));
        $student->setAttribute('duplicate_warnings', $this->completeness->duplicateWarnings($student));
        $student->setAttribute('physical_record_locator', $this->completeness->physicalLocator($student));

        return $student;
    }

    private function applySearch(Builder $query, string $search): void
    {
        $terms = collect(preg_split('/\s+/', trim($search)) ?: [])->filter()->values();
        $query->where(function (Builder $studentQuery) use ($search, $terms): void {
            $studentQuery->where('student_number', 'like', "%{$search}%")
                ->orWhereHas('userProfile', function (Builder $profileQuery) use ($terms): void {
                    foreach ($terms as $term) {
                        $profileQuery->where(fn (Builder $part) => $part
                            ->where('first_name', 'like', "%{$term}%")
                            ->orWhere('middle_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('suffix', 'like', "%{$term}%"));
                    }
                });
        });
    }

    private function applyAttention(Builder $query, string $attention): void
    {
        $profile = fn (Builder $q) => $q->whereNull('birth_date')->orWhereNull('email')->orWhereNull('contact_number')->orWhereNull('address');
        $documents = fn (Builder $q) => $q->where('availability_status', 'missing')
            ->orWhereIn('verification_status', ['pending', 'rejected'])
            ->orWhere('physical_copy_status', 'required');
        $query->where(function (Builder $attentionQuery) use ($attention, $profile, $documents): void {
            if (in_array($attention, ['any', 'physical_record'], true)) {
                $attentionQuery->orWhereDoesntHave('physicalRecordLocation');
            }
            if (in_array($attention, ['any', 'profile'], true)) {
                $attentionQuery->orWhereHas('userProfile', $profile);
            }
            if (in_array($attention, ['any', 'documents'], true)) {
                $attentionQuery->orWhereDoesntHave('documents')->orWhereHas('documents', $documents);
            }
        });
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $column = match ($sort) {
            'name' => UserProfile::query()->select('first_name')->whereColumn('user_profiles.id', 'students.user_profile_id')->limit(1),
            'program' => Course::query()->select('course_code')->whereColumn('courses.id', 'students.course_id')->limit(1),
            'year_level' => 'year_level',
            'student_status' => 'student_status',
            default => 'student_number',
        };
        $query->orderBy($column, $direction)->orderBy('student_number');
    }

    public function withCurrentEnrollment(Builder $query, bool $detail = false): Builder
    {
        $rankedEnrollment = DB::table('enrollments as ranked_enrollments')
            ->join('academic_years as ranked_years', 'ranked_years.id', '=', 'ranked_enrollments.academic_year_id')
            ->join('semesters as ranked_semesters', 'ranked_semesters.id', '=', 'ranked_enrollments.semester_id')
            ->leftJoin('enrollment_periods as ranked_periods', function ($join): void {
                $join->on('ranked_periods.academic_year_id', '=', 'ranked_enrollments.academic_year_id')
                    ->on('ranked_periods.semester_id', '=', 'ranked_enrollments.semester_id')
                    ->where('ranked_periods.enabled', true)
                    ->where('ranked_periods.opens_at', '<=', now())
                    ->where('ranked_periods.closes_at', '>=', now());
            })
            ->whereColumn('ranked_enrollments.student_id', 'students.id')
            ->select('ranked_enrollments.id')
            ->orderByRaw("CASE WHEN ranked_periods.id IS NOT NULL THEN 2 WHEN ranked_years.status = 'active' AND ranked_semesters.status = 'active' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('ranked_years.start_date')
            ->orderByDesc('ranked_semesters.semester_order')
            ->orderByDesc('ranked_enrollments.enrollment_date')
            ->orderByDesc('ranked_enrollments.id')
            ->limit(1);

        $rankedApplication = DB::table('enrollment_applications as ranked_applications')
            ->join('academic_years as application_years', 'application_years.id', '=', 'ranked_applications.academic_year_id')
            ->join('semesters as application_semesters', 'application_semesters.id', '=', 'ranked_applications.semester_id')
            ->join('enrollment_periods as application_periods', 'application_periods.id', '=', 'ranked_applications.period_id')
            ->whereColumn('ranked_applications.student_id', 'students.id')
            ->whereNotNull('ranked_applications.section_id')
            ->where('ranked_applications.status', 'approved')
            ->select('ranked_applications.id')
            ->orderByRaw("CASE WHEN application_periods.enabled = 1 AND application_periods.opens_at <= ? AND application_periods.closes_at >= ? THEN 2 WHEN application_years.status = 'active' AND application_semesters.status = 'active' THEN 1 ELSE 0 END DESC", [now(), now()])
            ->orderByDesc('application_years.start_date')
            ->orderByDesc('application_semesters.semester_order')
            ->orderByDesc('ranked_applications.id')
            ->limit(1);

        $relations = [
            'currentEnrollment.academicYear',
            'currentEnrollment.semester',
            'currentEnrollment.section',
            'currentEnrollment.application.course',
            'currentEnrollmentApplication.academicYear',
            'currentEnrollmentApplication.semester',
            'currentEnrollmentApplication.period',
            'currentEnrollmentApplication.section',
            'currentEnrollmentApplication.course',
        ];
        if ($detail) {
            $relations[] = 'currentEnrollment.application.period';
            $relations[] = 'currentEnrollment.application.curriculum';
            $relations[] = 'currentEnrollmentApplication.curriculum';
        }

        return $query
            ->addSelect([
                'students.*',
                'current_enrollment_id' => $rankedEnrollment,
                'current_enrollment_application_id' => $rankedApplication,
            ])
            ->with($relations);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, int $studentId, array $attributes): Student
    {
        return DB::transaction(function () use ($actor, $studentId, $attributes): Student {
            $student = Student::query()->with('userProfile')->findOrFail($studentId);

            $student->fill([
                'student_status' => $attributes['student_status'],
            ]);
            $studentFields = array_keys($student->getDirty());
            $student->save();

            $student->userProfile->fill([
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'last_name' => $attributes['last_name'],
                'suffix' => $attributes['suffix'] ?? null,
                'birth_date' => $attributes['birth_date'] ?? null,
                'gender' => $attributes['gender'] ?? $student->userProfile->gender,
                'civil_status' => $attributes['civil_status'] ?? null,
                'nationality' => $attributes['nationality'] ?? $student->userProfile->nationality,
                'contact_number' => $attributes['contact_number'] ?? null,
                'address' => $attributes['address'] ?? null,
            ]);
            $profileFields = array_keys($student->userProfile->getDirty());
            $student->userProfile->save();

            if ($profileFields !== []) {
                $this->audit->record($actor, $student->id, 'student_profile_updated', [
                    'changed_fields' => array_map(fn (string $field): string => 'profile.'.$field, $profileFields),
                ]);
            }
            if ($studentFields !== []) {
                $this->audit->record($actor, $student->id, 'student_status_changed', [
                    'changed_fields' => $studentFields,
                ]);
            }

            return $this->find($student->id);
        });
    }

    /**
     * @param  list<int>  $studentIds
     * @return array{updated_count: int, action: string}
     */
    public function bulkUpdate(User $actor, array $studentIds, string $action, mixed $value): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        return DB::transaction(function () use ($actor, $studentIds, $action, $value): array {
            $lockedStudentIds = Student::query()
                ->whereKey($studentIds)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (int $id): int => $id)
                ->all();

            if (count($lockedStudentIds) !== count($studentIds)) {
                throw ValidationException::withMessages([
                    'student_ids' => ['One or more selected student records no longer exist. Refresh the list and try again.'],
                ]);
            }

            $updatedCount = match ($action) {
                BulkUpdateStudentsRequest::CHANGE_STATUS => $this->updateStudentColumn(
                    $lockedStudentIds,
                    'student_status',
                    $value,
                ),
                BulkUpdateStudentsRequest::ASSIGN_RECORD_LOCATION => $this->assignRecordLocation(
                    $lockedStudentIds,
                    (int) $value['cabinet_slot_id'],
                ),
                BulkUpdateStudentsRequest::UPDATE_DOCUMENT_AVAILABILITY => $this->updateDocumentAvailability(
                    $lockedStudentIds,
                    (int) $value['document_type_id'],
                    $value['availability_status'],
                ),
                default => throw new \LogicException('Unsupported student bulk action.'),
            };

            $auditAction = match ($action) {
                BulkUpdateStudentsRequest::CHANGE_STATUS => 'student_status_changed',
                BulkUpdateStudentsRequest::ASSIGN_RECORD_LOCATION => 'physical_record_location_changed',
                BulkUpdateStudentsRequest::UPDATE_DOCUMENT_AVAILABILITY => 'document_availability_changed',
            };
            $metadata = match ($action) {
                BulkUpdateStudentsRequest::CHANGE_STATUS => ['changed_fields' => ['student_status']],
                BulkUpdateStudentsRequest::ASSIGN_RECORD_LOCATION => ['cabinet_slot_id' => (int) $value['cabinet_slot_id']],
                BulkUpdateStudentsRequest::UPDATE_DOCUMENT_AVAILABILITY => [
                    'document_type_id' => (int) $value['document_type_id'],
                    'availability_status' => $value['availability_status'],
                ],
            };
            foreach ($lockedStudentIds as $lockedStudentId) {
                $this->audit->record($actor, $lockedStudentId, $auditAction, $metadata);
            }

            return [
                'updated_count' => $updatedCount,
                'action' => $action,
            ];
        });
    }

    /** @param list<int> $studentIds */
    private function updateStudentColumn(array $studentIds, string $column, string|int $value): int
    {
        Student::query()->whereKey($studentIds)->update([
            $column => $value,
            'updated_at' => now(),
        ]);

        return count($studentIds);
    }

    /** @param list<int> $studentIds */
    private function assignRecordLocation(array $studentIds, int $cabinetSlotId): int
    {
        $slot = CabinetSlot::query()->whereKey($cabinetSlotId)->lockForUpdate()->firstOrFail();

        if ($slot->status !== 'active') {
            throw ValidationException::withMessages([
                'value.cabinet_slot_id' => ['The selected cabinet slot is inactive. Choose an active slot.'],
            ]);
        }

        $existingLocations = StudentRecordLocation::query()
            ->whereIn('student_id', $studentIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('student_id');

        if ($slot->capacity !== null) {
            $currentRecordCount = StudentRecordLocation::query()
                ->where('cabinet_slot_id', $slot->id)
                ->count();
            $newRecordCount = collect($studentIds)
                ->reject(fn (int $studentId): bool => $existingLocations->get($studentId)?->cabinet_slot_id === $slot->id)
                ->count();

            if ($currentRecordCount + $newRecordCount > $slot->capacity) {
                throw ValidationException::withMessages([
                    'value.cabinet_slot_id' => [sprintf(
                        'The selected cabinet slot has room for %d more record(s), but %d selected record(s) require space.',
                        max(0, $slot->capacity - $currentRecordCount),
                        $newRecordCount,
                    )],
                ]);
            }
        }

        $now = now();
        $rows = collect($studentIds)->map(function (int $studentId) use ($cabinetSlotId, $existingLocations, $now): array {
            $existing = $existingLocations->get($studentId);

            return [
                'student_id' => $studentId,
                'cabinet_slot_id' => $cabinetSlotId,
                'assigned_at' => $now,
                'remarks' => $existing?->remarks,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ];
        });

        $rows->chunk(100)->each(fn ($chunk) => StudentRecordLocation::query()->upsert(
            $chunk->all(),
            ['student_id'],
            ['cabinet_slot_id', 'assigned_at', 'remarks', 'updated_at'],
        ));

        return count($studentIds);
    }

    /** @param list<int> $studentIds */
    private function updateDocumentAvailability(array $studentIds, int $documentTypeId, string $availability): int
    {
        $documentStudentIds = StudentDocument::query()
            ->whereIn('student_id', $studentIds)
            ->where('document_type_id', $documentTypeId)
            ->lockForUpdate()
            ->pluck('student_id')
            ->map(fn (int $id): int => $id)
            ->all();
        $missingStudentIds = array_values(array_diff($studentIds, $documentStudentIds));

        if ($missingStudentIds !== []) {
            throw ValidationException::withMessages([
                'student_ids' => [sprintf(
                    'No matching student document exists for student ID(s): %s. No records were changed.',
                    implode(', ', array_slice($missingStudentIds, 0, 20)),
                )],
            ]);
        }

        StudentDocument::query()
            ->whereIn('student_id', $studentIds)
            ->where('document_type_id', $documentTypeId)
            ->update([
                'availability_status' => $availability,
                'updated_at' => now(),
            ]);

        return count($studentIds);
    }
}

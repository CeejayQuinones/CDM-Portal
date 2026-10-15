<?php

namespace App\Services;

use App\Http\Requests\BulkUpdateStudentsRequest;
use App\Models\CabinetSlot;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentRecordLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Student::query()
            ->with(['user', 'userProfile', 'course', 'curriculum'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($studentQuery) use ($search): void {
                    $studentQuery->where('student_number', 'like', "%{$search}%")
                        ->orWhereHas('userProfile', fn ($profileQuery) => $profileQuery
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['course'] ?? null, function ($query, string $course): void {
                $query->where(function ($courseQuery) use ($course): void {
                    $courseQuery->where('course_id', $course)
                        ->orWhereHas('course', fn ($relationQuery) => $relationQuery->where('course_code', $course));
                });
            })
            ->when($filters['year_level'] ?? null, fn ($query, int $yearLevel) => $query->where('year_level', $yearLevel))
            ->when($filters['student_status'] ?? null, fn ($query, string $status) => $query->where('student_status', $status))
            ->orderBy('student_number')
            ->paginate(15)
            ->withQueryString();
    }

    public function find(int $studentId): Student
    {
        return Student::query()
            ->with([
                'user.role',
                'userProfile',
                'course.department',
                'curriculum',
                'latestEnrollment.academicYear',
                'latestEnrollment.semester',
                'documents.documentType',
                'documents.aiAnalysis',
                'physicalRecordLocation.cabinetSlot.cabinet',
                'documentRequests' => fn ($requests) => $requests
                    ->with('documentType:id,document_name')
                    ->latest()
                    ->limit(10),
            ])
            ->findOrFail($studentId);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $studentId, array $attributes): Student
    {
        return DB::transaction(function () use ($studentId, $attributes): Student {
            $student = Student::query()->with('userProfile')->findOrFail($studentId);

            $student->update([
                'student_number' => $attributes['student_number'] ?? $student->student_number,
                'course_id' => $attributes['course_id'],
                'year_level' => $attributes['year_level'],
                'student_status' => $attributes['student_status'],
            ]);

            $student->userProfile->update([
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

            return $this->find($student->id);
        });
    }

    /**
     * @param  list<int>  $studentIds
     * @return array{updated_count: int, action: string}
     */
    public function bulkUpdate(array $studentIds, string $action, mixed $value): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        return DB::transaction(function () use ($studentIds, $action, $value): array {
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
                BulkUpdateStudentsRequest::CHANGE_YEAR_LEVEL => $this->updateStudentColumn(
                    $lockedStudentIds,
                    'year_level',
                    (int) $value,
                ),
                BulkUpdateStudentsRequest::CHANGE_COURSE => $this->updateStudentColumn(
                    $lockedStudentIds,
                    'course_id',
                    (int) $value,
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

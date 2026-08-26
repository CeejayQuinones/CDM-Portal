<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCabinetRequest;
use App\Http\Requests\UpdateCabinetSlotRequest;
use App\Http\Requests\UpdateStudentRecordLocationRequest;
use App\Http\Resources\CabinetResource;
use App\Http\Resources\StudentDocumentResource;
use App\Http\Resources\StudentRecordLocationResource;
use App\Models\Cabinet;
use App\Models\CabinetSlot;
use App\Models\Student;
use App\Models\StudentRecordLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrarCabinetController extends Controller
{
    public function index(): JsonResponse
    {
        $cabinets = $this->summaryQuery()->get();

        return $this->ok(
            CabinetResource::collection($cabinets),
            'Cabinets retrieved successfully.',
        );
    }

    public function store(StoreCabinetRequest $request): JsonResponse
    {
        $attributes = $request->validated();

        $cabinet = DB::transaction(function () use ($attributes): Cabinet {
            $cabinet = Cabinet::query()->create([
                'cabinet_code' => $attributes['cabinet_code'],
                'description' => $attributes['description'] ?? null,
                'rows' => $attributes['rows'],
                'columns' => $attributes['columns'],
            ]);

            $now = now();
            $slots = [];
            $numberOfSlots = $cabinet->rows * $cabinet->columns;

            for ($number = 1; $number <= $numberOfSlots; $number++) {
                $slots[] = [
                    'cabinet_id' => $cabinet->id,
                    'slot_code' => $cabinet->cabinet_code.$number,
                    'capacity' => $attributes['slot_capacity'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            CabinetSlot::query()->insert($slots);

            return $cabinet;
        });

        return $this->ok(
            new CabinetResource($this->summaryQuery()->findOrFail($cabinet->id)),
            'Cabinet created successfully.',
            201,
        );
    }

    public function show(Cabinet $cabinet): JsonResponse
    {
        return $this->ok(
            new CabinetResource($this->summaryQuery()->findOrFail($cabinet->id)),
            'Cabinet retrieved successfully.',
        );
    }

    public function showSlot(Request $request, CabinetSlot $cabinetSlot): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $locations = StudentRecordLocation::query()
            ->select(['id', 'student_id', 'cabinet_slot_id', 'assigned_at', 'remarks'])
            ->where('cabinet_slot_id', $cabinetSlot->id)
            ->with([
                'student:id,user_profile_id,course_id,student_number,year_level,student_status',
                'student.userProfile:id,first_name,middle_name,last_name,suffix,profile_photo',
                'student.course:id,course_code,course_name',
                'student.documents' => fn ($documents) => $documents
                    ->select([
                        'id', 'student_id', 'document_type_id', 'verification_status',
                        'availability_status', 'remarks', 'submitted_date',
                    ])
                    ->with('documentType:id,document_name')
                    ->orderBy('document_type_id'),
            ])
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->whereHas('student', function (Builder $students) use ($search): void {
                    $students->where('student_number', 'like', "%{$search}%")
                        ->orWhereHas('userProfile', function (Builder $profiles) use ($search): void {
                            $profiles->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy(Student::query()
                ->select('student_number')
                ->whereColumn('students.id', 'student_record_locations.student_id'))
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        $locations->through(function (StudentRecordLocation $location) use ($request): array {
            $student = $location->student;
            $profile = $student->userProfile;
            $documents = $student->documents
                ->map(fn ($document) => (new StudentDocumentResource($document))->toArray($request))
                ->values();

            return [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'full_name' => collect([
                    $profile->first_name,
                    $profile->middle_name,
                    $profile->last_name,
                    $profile->suffix,
                ])->filter()->join(' '),
                'course' => $student->course ? [
                    'id' => $student->course->id,
                    'code' => $student->course->course_code,
                    'name' => $student->course->course_name,
                ] : null,
                'year_level' => $student->year_level,
                'student_status' => $student->student_status,
                'profile_photo' => $profile->profile_photo,
                'assigned_at' => $location->assigned_at?->toISOString(),
                'location_remarks' => $location->remarks,
                'documents' => $documents,
                'available_documents' => $documents->where('status', 'available')->values(),
                'missing_documents' => $documents->where('status', 'missing')->values(),
            ];
        });

        $cabinetSlot->load('cabinet:id,cabinet_code,description,rows,columns')
            ->loadCount('studentRecordLocations');

        return $this->ok([
            'id' => $cabinetSlot->id,
            'slot_code' => $cabinetSlot->slot_code,
            'capacity' => $cabinetSlot->capacity,
            'size' => $cabinetSlot->size,
            'description' => $cabinetSlot->description,
            'status' => $cabinetSlot->status,
            'record_count' => (int) $cabinetSlot->student_record_locations_count,
            'cabinet' => [
                'id' => $cabinetSlot->cabinet->id,
                'cabinet_code' => $cabinetSlot->cabinet->cabinet_code,
                'description' => $cabinetSlot->cabinet->description,
                'rows' => $cabinetSlot->cabinet->rows,
                'columns' => $cabinetSlot->cabinet->columns,
            ],
            'students' => $locations,
        ], 'Cabinet slot retrieved successfully.');
    }

    public function updateSlot(
        UpdateCabinetSlotRequest $request,
        CabinetSlot $cabinetSlot,
    ): JsonResponse {
        $attributes = $request->validated();

        $slot = DB::transaction(function () use ($cabinetSlot, $attributes): CabinetSlot {
            $slot = CabinetSlot::query()->whereKey($cabinetSlot->id)->lockForUpdate()->firstOrFail();
            $recordCount = StudentRecordLocation::query()
                ->where('cabinet_slot_id', $slot->id)
                ->count();

            if ($attributes['capacity'] !== null && $attributes['capacity'] < $recordCount) {
                throw ValidationException::withMessages([
                    'capacity' => [sprintf(
                        'This slot currently contains %d records. Capacity cannot be reduced below %d.',
                        $recordCount,
                        $recordCount,
                    )],
                ]);
            }

            if ($attributes['status'] === 'inactive' && $recordCount > 0) {
                throw ValidationException::withMessages([
                    'status' => ['Move the existing records before disabling this slot.'],
                ]);
            }

            $slot->update($attributes);

            return $slot;
        });

        $slot->load('cabinet:id,cabinet_code,description,rows,columns')
            ->loadCount('studentRecordLocations');

        return $this->ok([
            'id' => $slot->id,
            'slot_code' => $slot->slot_code,
            'capacity' => $slot->capacity,
            'size' => $slot->size,
            'description' => $slot->description,
            'status' => $slot->status,
            'record_count' => (int) $slot->student_record_locations_count,
            'cabinet' => [
                'id' => $slot->cabinet->id,
                'cabinet_code' => $slot->cabinet->cabinet_code,
                'description' => $slot->cabinet->description,
                'rows' => $slot->cabinet->rows,
                'columns' => $slot->cabinet->columns,
            ],
        ], 'Cabinet slot updated successfully.');
    }

    public function updateStudentLocation(
        UpdateStudentRecordLocationRequest $request,
        Student $student,
    ): JsonResponse {
        $attributes = $request->validated();

        $location = DB::transaction(function () use ($student, $attributes): StudentRecordLocation {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $slot = CabinetSlot::query()->whereKey($attributes['cabinet_slot_id'])->lockForUpdate()->firstOrFail();

            if ($slot->status !== 'active') {
                throw ValidationException::withMessages([
                    'cabinet_slot_id' => ['The selected cabinet slot is inactive. Choose an active slot.'],
                ]);
            }

            if ($slot->capacity !== null) {
                $currentRecords = StudentRecordLocation::query()
                    ->where('cabinet_slot_id', $slot->id)
                    ->where('student_id', '!=', $student->id)
                    ->count();

                if ($currentRecords >= $slot->capacity) {
                    throw ValidationException::withMessages([
                        'cabinet_slot_id' => ['The selected cabinet slot is already at capacity.'],
                    ]);
                }
            }

            $location = StudentRecordLocation::query()
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            $locationAttributes = [
                'cabinet_slot_id' => $slot->id,
                'assigned_at' => now(),
                'remarks' => $attributes['remarks'] ?? null,
            ];

            if ($location) {
                $location->update($locationAttributes);

                return $location;
            }

            return StudentRecordLocation::query()->create([
                'student_id' => $student->id,
                ...$locationAttributes,
            ]);
        });

        $location->load('cabinetSlot.cabinet');

        return $this->ok(
            new StudentRecordLocationResource($location),
            'Student physical record location updated successfully.',
        );
    }

    private function summaryQuery(): Builder
    {
        return Cabinet::query()
            ->select(['id', 'cabinet_code', 'description', 'rows', 'columns', 'created_at', 'updated_at'])
            ->withCount('slots')
            ->with(['slots' => fn ($slots) => $slots
                ->select(['id', 'cabinet_id', 'slot_code', 'capacity', 'size', 'description', 'status'])
                ->withCount('studentRecordLocations')
                ->orderBy('id')])
            ->orderBy('cabinet_code');
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}

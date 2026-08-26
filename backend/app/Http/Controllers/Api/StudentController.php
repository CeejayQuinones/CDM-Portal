<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUpdateStudentsRequest;
use App\Http\Requests\StudentIndexRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentDocumentResource;
use App\Http\Resources\StudentResource;
use App\Models\Cabinet;
use App\Models\Course;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $studentService) {}

    public function index(StudentIndexRequest $request): JsonResponse
    {
        $students = $this->studentService->paginate($request->validated());

        return StudentResource::collection($students)
            ->additional([
                'success' => true,
                'message' => 'Student records retrieved successfully.',
            ])
            ->response();
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Student record retrieved successfully.',
            'data' => new StudentResource($this->studentService->find($student->id)),
        ]);
    }

    public function bulkOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $isRegistrar = $user instanceof User && $user->role?->role_name === Role::REGISTRAR_STAFF;

        return response()->json([
            'success' => true,
            'message' => 'Student bulk-update options retrieved successfully.',
            'data' => [
                'actions' => array_values(array_filter([
                    ['value' => BulkUpdateStudentsRequest::CHANGE_STATUS, 'label' => 'Change Student Status'],
                    ['value' => BulkUpdateStudentsRequest::CHANGE_YEAR_LEVEL, 'label' => 'Change Year Level'],
                    ['value' => BulkUpdateStudentsRequest::CHANGE_COURSE, 'label' => 'Change Course'],
                    $isRegistrar ? ['value' => BulkUpdateStudentsRequest::ASSIGN_RECORD_LOCATION, 'label' => 'Assign / Change Physical Record Location'] : null,
                    $isRegistrar ? ['value' => BulkUpdateStudentsRequest::UPDATE_DOCUMENT_AVAILABILITY, 'label' => 'Update Student Document Availability'] : null,
                ])),
                'courses' => Course::query()
                    ->where('status', 'active')
                    ->orderBy('course_code')
                    ->get(['id', 'course_code', 'course_name']),
                'cabinets' => $isRegistrar ? Cabinet::query()
                    ->with(['slots' => fn ($query) => $query
                        ->where('status', 'active')
                        ->withCount('studentRecordLocations')
                        ->orderBy('slot_code')])
                    ->orderBy('cabinet_code')
                    ->get()
                    ->map(fn (Cabinet $cabinet): array => [
                        'id' => $cabinet->id,
                        'code' => $cabinet->cabinet_code,
                        'description' => $cabinet->description,
                        'slots' => $cabinet->slots->map(fn ($slot): array => [
                            'id' => $slot->id,
                            'code' => $slot->slot_code,
                            'capacity' => $slot->capacity,
                            'size' => $slot->size,
                            'status' => $slot->status,
                            'record_count' => (int) $slot->student_record_locations_count,
                        ])->values(),
                    ])->values() : [],
                'document_types' => $isRegistrar ? DocumentType::query()
                    ->orderBy('document_name')
                    ->get(['id', 'document_name']) : [],
            ],
        ]);
    }

    public function bulkUpdate(BulkUpdateStudentsRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $result = $this->studentService->bulkUpdate(
            $attributes['student_ids'],
            $attributes['action'],
            $attributes['value'],
        );

        return response()->json([
            'success' => true,
            'message' => sprintf('%d student records updated successfully.', $result['updated_count']),
            'data' => $result,
        ]);
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Student record updated successfully.',
            'data' => new StudentResource($this->studentService->update($student->id, $request->validated())),
        ]);
    }

    public function documents(Student $student): JsonResponse
    {
        $student = $this->studentService->find($student->id);
        $documents = $student->documents()->with('documentType')->orderByDesc('submitted_date')->get();

        return response()->json([
            'success' => true,
            'message' => 'Student documents retrieved successfully.',
            'data' => [
                'student' => new StudentResource($student),
                'documents' => StudentDocumentResource::collection($documents),
            ],
        ]);
    }
}

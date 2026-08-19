<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentIndexRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Http\Resources\StudentDocumentResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $studentService)
    {
    }

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

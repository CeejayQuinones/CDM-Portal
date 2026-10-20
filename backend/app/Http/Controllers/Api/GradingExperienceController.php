<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeConversation;
use App\Models\GradeMessage;
use App\Models\GradeSheet;
use App\Models\Student;
use App\Services\Grading\GradeMessageService;
use App\Services\Grading\StudentGradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradingExperienceController extends Controller
{
    public function __construct(private readonly StudentGradeService $grades, private readonly GradeMessageService $messages) {}

    public function studentGrades(Request $request): JsonResponse
    {
        return $this->ok($this->grades->report($request->user(), $this->termFilters($request)));
    }

    public function studentExport(Request $request): StreamedResponse
    {
        return $this->grades->studentCsv($request->user(), $this->termFilters($request));
    }

    public function staffStudentGrades(Request $request, Student $student): JsonResponse
    {
        return $this->ok($this->grades->forStudent($student, $this->termFilters($request)));
    }

    public function staffStudentExport(Request $request, Student $student): StreamedResponse
    {
        return $this->grades->studentCsv($request->user(), $this->termFilters($request), $student);
    }

    public function classExport(Request $request, GradeSheet $gradeSheet): StreamedResponse
    {
        return $this->grades->classCsv($request->user(), $gradeSheet);
    }

    public function createConversation(Request $request, GradeSheet $gradeSheet): JsonResponse
    {
        return $this->ok($this->messages->createStudentConversation($request->user(), $gradeSheet), 201);
    }

    public function professorConversations(Request $request): JsonResponse
    {
        return $this->ok($this->messages->professorConversations($request->user()));
    }

    public function conversation(Request $request, GradeConversation $gradeConversation): JsonResponse
    {
        return $this->ok($this->messages->open($request->user(), $gradeConversation));
    }

    public function sendMessage(Request $request, GradeConversation $gradeConversation): JsonResponse
    {
        $input = $request->validate([
            'body' => 'nullable|string|max:5000|required_without:attachment',
            'attachment' => 'nullable|file|image|mimes:jpeg,jpg,png,webp,gif|max:5120|required_without:body',
        ]);

        return $this->ok($this->messages->send($request->user(), $gradeConversation, $input['body'] ?? null, $request->file('attachment')), 201);
    }

    public function unsendMessage(Request $request, GradeMessage $gradeMessage): JsonResponse
    {
        return $this->ok($this->messages->unsend($request->user(), $gradeMessage));
    }

    public function attachment(Request $request, GradeMessage $gradeMessage): StreamedResponse
    {
        return $this->messages->attachment($request->user(), $gradeMessage);
    }

    private function termFilters(Request $request): array
    {
        return $request->validate(['academic_year_id' => 'nullable|integer|exists:academic_years,id', 'semester_id' => 'nullable|integer|exists:semesters,id']);
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}

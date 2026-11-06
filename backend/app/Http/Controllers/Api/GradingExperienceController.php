<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeSheet;
use App\Models\Student;
use App\Services\Grading\StudentGradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradingExperienceController extends Controller
{
    public function __construct(private readonly StudentGradeService $grades) {}

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

    private function termFilters(Request $request): array
    {
        return $request->validate(['academic_year_id' => 'nullable|integer|exists:academic_years,id', 'semester_id' => 'nullable|integer|exists:semesters,id']);
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HistoricalGradeRecord;
use App\Models\Student;
use App\Services\StudentRecords\HistoricalGradeImportService;
use App\Services\StudentRecords\HistoricalGradeService;
use App\Services\StudentRecords\HistoricalGradeWorkbookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoricalGradeController extends Controller
{
    public function __construct(
        private readonly HistoricalGradeService $grades,
        private readonly HistoricalGradeWorkbookService $workbook,
        private readonly HistoricalGradeImportService $imports,
    ) {}

    public function options(): JsonResponse
    {
        return $this->ok($this->grades->options());
    }

    public function template(): StreamedResponse
    {
        return $this->workbook->template();
    }

    public function index(Student $student): JsonResponse
    {
        return $this->ok($this->grades->forStudent($student));
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $record = $this->grades->create(array_merge($request->all(), ['student_number' => $student->student_number]), $request->user(), 'manual', $student);

        return $this->ok($record, 201);
    }

    public function correct(Request $request, Student $student, HistoricalGradeRecord $record): JsonResponse
    {
        abort_unless($record->student_id === $student->id, 404);
        $corrected = $this->grades->correct($record, array_merge($request->all(), ['student_number' => $student->student_number]), $request->user());

        return $this->ok($corrected);
    }

    public function preview(Request $request): JsonResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'max:2048']])['file'];

        return $this->ok($this->imports->preview($this->workbook->rows($file), $request->user()));
    }

    public function confirm(Request $request): JsonResponse
    {
        $token = $request->validate(['preview_token' => ['required', 'string', 'size:64']])['preview_token'];

        return $this->ok($this->imports->confirm($token, $request->user()));
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRecordReportRequest;
use App\Services\StudentRecords\StudentRecordExportService;
use App\Services\StudentRecords\StudentRecordReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentRecordReportController extends Controller
{
    public function __construct(
        private readonly StudentRecordReportService $reports,
        private readonly StudentRecordExportService $exports,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->reports->options()]);
    }

    public function index(StudentRecordReportRequest $request, string $reportType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reports->report($reportType, $request->validated()),
        ]);
    }

    public function export(StudentRecordReportRequest $request, string $reportType): StreamedResponse
    {
        $filters = $request->validated();
        unset($filters['page'], $filters['per_page'], $filters['report_type']);

        return response()->streamDownload(function () use ($reportType, $filters): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $this->reports->csvHeaders($reportType));

            foreach ($this->reports->exportRows($reportType, $filters) as $row) {
                fputcsv($stream, $row);
            }

            fclose($stream);
        }, $this->reports->filename($reportType, $filters), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function excel(StudentRecordReportRequest $request, string $reportType): BinaryFileResponse
    {
        return $this->exports->excel($reportType, $this->exportFilters($request));
    }

    public function pdf(StudentRecordReportRequest $request, string $reportType): Response
    {
        return $this->exports->pdf($reportType, $this->exportFilters($request));
    }

    /** @return array<string, mixed> */
    private function exportFilters(StudentRecordReportRequest $request): array
    {
        $filters = $request->validated();
        unset($filters['page'], $filters['per_page'], $filters['report_type']);

        return $filters;
    }
}

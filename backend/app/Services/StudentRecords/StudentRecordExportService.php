<?php

namespace App\Services\StudentRecords;

use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class StudentRecordExportService
{
    public function __construct(private readonly StudentRecordReportService $reports) {}

    /** @param array<string, mixed> $filters */
    public function excel(string $type, array $filters): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('Colegio de Montalban Registrar Office')->setTitle($this->reports->title($type));
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($this->reports->title($type), 0, 31));
        $headers = $this->reports->csvHeaders($type);
        $sheet->fromArray($headers, null, 'A1');
        $dateColumns = collect($headers)->keys()->filter(fn (int $index): bool => (bool) preg_match('/date|time/i', $headers[$index]));
        $row = 2;
        foreach ($this->reports->exportRows($type, $filters) as $values) {
            $sheet->fromArray($values, null, 'A'.$row++);
            foreach ($dateColumns as $index) {
                $value = $values[$index] ?? null;
                if (! is_string($value) || blank($value) || $value === 'Not available') {
                    continue;
                }
                try {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).($row - 1));
                    $cell->setValue(Date::PHPToExcel(Carbon::parse($value)));
                    $cell->getStyle()->getNumberFormat()->setFormatCode(str_contains($value, ':') ? 'yyyy-mm-dd hh:mm' : 'yyyy-mm-dd');
                } catch (\Throwable) {
                    // Keep source text when a report value is not a parseable date.
                }
            }
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF176B46');
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->setAutoFilter("A1:{$lastColumn}".max(1, $row - 1));
        $sheet->freezePane('A2');
        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'student-records-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $this->reports->filename($type, $filters, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    /** @param array<string, mixed> $filters */
    public function pdf(string $type, array $filters): Response
    {
        $headers = $this->reports->csvHeaders($type);
        $rows = iterator_to_array($this->reports->exportRows($type, $filters), false);
        $filterText = collect($this->reports->filterLabels($filters))
            ->map(fn (string $value, string $key): string => $this->escape(str_replace('_', ' ', ucfirst($key)).': '.$value))
            ->implode(' · ');
        $head = collect($headers)->map(fn (string $header): string => '<th>'.$this->escape($header).'</th>')->implode('');
        $body = collect($rows)->map(fn (array $row): string => '<tr>'.collect($row)->map(fn ($value): string => '<td>'.$this->escape((string) $value).'</td>')->implode('').'</tr>')->implode('');
        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>@page{margin:36px 28px 44px}body{font-family:DejaVu Sans,sans-serif;color:#152d25;font-size:9px}h1,h2,p{text-align:center;margin:3px}h2{color:#176b46}.meta{margin:12px 0;text-align:left}table{width:100%;border-collapse:collapse}th{background:#176b46;color:#fff}th,td{border:1px solid #b9c8c0;padding:4px;text-align:left;word-break:break-word}tr:nth-child(even){background:#f1f6f3}</style></head><body><h1>Colegio de Montalban</h1><p>Registrar Office</p><h2>'.$this->escape($this->reports->title($type)).'</h2><div class="meta">Generated: '.$this->escape(now()->format('Y-m-d H:i:s')).'<br>Record count: '.count($rows).($filterText ? '<br>Applied filters: '.$filterText : '').'</div><table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', count($headers) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 25, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.2, 0.2, 0.2]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->reports->filename($type, $filters, 'pdf').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

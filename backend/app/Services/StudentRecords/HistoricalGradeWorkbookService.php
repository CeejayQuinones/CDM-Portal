<?php

namespace App\Services\StudentRecords;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class HistoricalGradeWorkbookService
{
    public const HEADERS = ['Student Number', 'Academic Year', 'Semester', 'Subject Code', 'Final Grade', 'Official Result', 'Credited Units', 'Source Reference'];

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Historical grades');
            foreach (self::HEADERS as $index => $heading) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).'1', $heading, DataType::TYPE_STRING);
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($index === 7 ? 32 : 22);
            }
            $sheet->freezePane('A2');
            $instructions = $book->createSheet();
            $instructions->setTitle('Instructions');
            foreach ([
                'One official subject result per row, starting on row 2. Maximum 200 rows per upload.',
                'Use exact existing Student Numbers, configured academic years and semesters, and subject codes from the Student curriculum.',
                'Official Result: Passed, Failed, or Incomplete. Do not infer this from the grade.',
                'Source Reference must identify the official document or register used for verification.',
                'Use plain values only; formulas and ambiguous subjects are rejected. Preview before confirming.',
            ] as $index => $message) {
                $instructions->setCellValueExplicit('A'.($index + 1), $message, DataType::TYPE_STRING);
            }
            $instructions->getColumnDimension('A')->setWidth(120);
            (new XlsxWriter($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 'historical-grades-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function rows(UploadedFile $file): array
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'xlsx' || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['Upload a .xlsx workbook smaller than 2 MB.']]);
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => ['The workbook is invalid or password protected.']]);
        }
        try {
            if ($zip->numFiles > 2000 || $zip->numFiles < 3 || $zip->locateName('xl/workbook.xml') === false) {
                throw ValidationException::withMessages(['file' => ['The workbook structure is unsupported.']]);
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (! $entry || ($entry['encryption_method'] ?? 0) !== 0 || ($entry['size'] ?? 0) > 8 * 1024 * 1024) {
                    throw ValidationException::withMessages(['file' => ['The workbook contains an encrypted or oversized entry.']]);
                }
                $total += $entry['size'];
                if ($total > 16 * 1024 * 1024 || ($entry['size'] > 1024 * 1024 && $entry['size'] > $entry['comp_size'] * 100)) {
                    throw ValidationException::withMessages(['file' => ['The workbook expands beyond the safe processing limit.']]);
                }
            }
        } finally {
            $zip->close();
        }

        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $book = $reader->load($file->getRealPath());
            if ($book->getSheetCount() !== 1 && ($book->getSheetCount() !== 2 || $book->getSheet(1)->getTitle() !== 'Instructions')) {
                throw new \UnexpectedValueException('Unexpected worksheets.');
            }
            $sheet = $book->getSheet(0);
            if ($sheet->getHighestDataRow() > 201 || $sheet->getHighestDataColumn() !== 'H') {
                throw new \UnexpectedValueException('Maximum 200 data rows and eight columns.');
            }
            foreach (self::HEADERS as $index => $heading) {
                if (trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).'1')->getValue()) !== $heading) {
                    throw new \UnexpectedValueException('Required column headings are missing or reordered.');
                }
            }
            $rows = [];
            for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                $values = [];
                foreach (range(1, 8) as $column) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$number);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw ValidationException::withMessages(['file' => ["Row {$number}: formulas are not accepted."]]);
                    }
                    $values[] = trim((string) ($cell->getValue() ?? ''));
                }
                if (array_filter($values, fn ($value) => $value !== '')) {
                    $rows[] = ['row' => $number, 'data' => array_combine(['student_number', ...HistoricalGradeService::FIELDS], $values)];
                }
            }
            $book->disconnectWorksheets();
            if (! $rows) {
                throw new \UnexpectedValueException('No grade rows were found.');
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => ['Invalid workbook, headings, or row limit. Download a fresh template and enter plain values.']]);
        }
    }
}

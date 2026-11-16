<?php

namespace App\Services\StudentRecords;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Accepts a canonical CSV or an Access export with recognized column aliases. */
class HistoricalGradeCsvService
{
    private const REQUIRED = ['student_number', 'academic_year', 'semester', 'subject_code', 'final_grade', 'official_result', 'credited_units', 'source_reference'];

    public function rows(UploadedFile $file): array
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv' || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['Upload a .csv file smaller than 2 MB.']]);
        }
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => ['CSV file could not be read.']]);
        }
        try {
            $headers = fgetcsv($stream, null, ',', '"', '');
            if ($headers === false) {
                throw ValidationException::withMessages(['file' => ['CSV file has no header row.']]);
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $columns = [];
            foreach ($headers as $index => $header) {
                $normalized = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $header))), '_');
                foreach (LegacyExportInspector::ALIASES as $field => $aliases) {
                    if (in_array($normalized, $aliases, true) && in_array($field, self::REQUIRED, true)) {
                        if (isset($columns[$field])) {
                            throw ValidationException::withMessages(['file' => ["More than one column maps to {$field}."]]);
                        }
                        $columns[$field] = $index;
                    }
                }
            }
            if (array_diff(self::REQUIRED, array_keys($columns))) {
                throw ValidationException::withMessages(['file' => ['CSV is missing required grade columns: '.implode(', ', array_diff(self::REQUIRED, array_keys($columns))).'.']]);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => ["CSV row {$line} has a different column count."]]);
                }
                $data = [];
                foreach (self::REQUIRED as $field) {
                    $data[$field] = trim((string) $values[$columns[$field]]);
                }
                $rows[] = ['row' => $line, 'data' => $data];
                if (count($rows) > 200) {
                    throw ValidationException::withMessages(['file' => ['Import at most 200 grade rows per file. Use legacy staging for larger exports.']]);
                }
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => ['CSV has no grade rows.']]);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }
}

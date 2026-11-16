<?php

namespace App\Services\StudentRecords;

use Generator;
use InvalidArgumentException;

/** Read-only inspection of a school-provided CSV export; never persists or prints row data. */
class LegacyExportInspector
{
    public const ALIASES = [
        'student_number' => ['student_number', 'student_no', 'student_id', 'stud_no'],
        'first_name' => ['first_name', 'firstname', 'given_name'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name'],
        'middle_name' => ['middle_name', 'middlename'],
        'gender' => ['gender', 'sex'],
        'enrollment_email' => ['enrollment_email', 'email'],
        'record_reference' => ['record_reference', 'file_reference', 'archive_reference'],
        'course_code' => ['course_code', 'program_code', 'course', 'program'],
        'curriculum_code' => ['curriculum_code', 'curriculum'],
        'year_level' => ['year_level', 'year'],
        'student_status' => ['student_status', 'status'],
        'admission_date' => ['admission_date', 'date_of_admission', 'date_admission'],
        'academic_year' => ['academic_year', 'school_year', 'school_academic_year'],
        'semester' => ['semester', 'term'],
        'subject_code' => ['subject_code', 'subject_no'],
        'final_grade' => ['final_grade', 'grade'],
        'remarks' => ['remarks', 'result'],
        'official_result' => ['official_result', 'verified_result'],
        'credited_units' => ['credited_units', 'credits', 'units'],
        'source_reference' => ['source_reference', 'grade_source_reference'],
    ];

    public function inspect(string $path, string $entity): array
    {
        if (! in_array($entity, ['students', 'grades'], true)) {
            throw new InvalidArgumentException('Entity must be students or grades.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('CSV file is missing or unreadable.');
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('CSV file could not be opened.');
        }
        try {
            $headers = fgetcsv($stream, null, ',', '"', '');
            if ($headers === false || $headers === [null]) {
                throw new InvalidArgumentException('CSV file has no header row.');
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $normalized = array_map($this->normalize(...), $headers);
            if (count($normalized) !== count(array_unique($normalized))) {
                throw new InvalidArgumentException('CSV file has duplicate column names.');
            }
            $columns = [];
            foreach (self::ALIASES as $canonical => $aliases) {
                $matches = array_intersect($normalized, $aliases);
                if (count($matches) > 1) {
                    throw new InvalidArgumentException("More than one column maps to {$canonical}.");
                }
                if ($matches !== []) {
                    $columns[$canonical] = array_key_first($matches);
                }
            }
            $required = $entity === 'students'
                ? ['student_number', 'first_name', 'last_name']
                : ['student_number', 'academic_year', 'semester', 'subject_code', 'final_grade', 'remarks', 'credited_units'];
            $missing = array_values(array_diff($required, array_keys($columns)));
            $blankCounts = array_fill_keys(array_keys($columns), 0);
            $counts = ['rows' => 0, 'malformed_rows' => 0, 'blank_student_numbers' => 0, 'duplicate_keys' => 0];
            $seen = [];
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                $counts['rows']++;
                if (count($values) !== count($headers)) {
                    $counts['malformed_rows']++;

                    continue;
                }
                foreach ($columns as $name => $index) {
                    if (trim((string) $values[$index]) === '') {
                        $blankCounts[$name]++;
                    }
                }
                if (! isset($columns['student_number'])) {
                    continue;
                }
                $studentNumber = mb_strtolower(trim((string) $values[$columns['student_number']]));
                if ($studentNumber === '') {
                    $counts['blank_student_numbers']++;

                    continue;
                }
                $parts = [$studentNumber];
                if ($entity === 'grades') {
                    foreach (['academic_year', 'semester', 'subject_code'] as $field) {
                        if (! isset($columns[$field])) {
                            continue 2;
                        }
                        $parts[] = mb_strtolower(trim((string) $values[$columns[$field]]));
                    }
                }
                $key = hash('sha256', implode("\0", $parts));
                if (isset($seen[$key])) {
                    $counts['duplicate_keys']++;
                }
                $seen[$key] = true;
            }

            return [
                'entity' => $entity,
                'sha256' => hash_file('sha256', $path),
                'recognized_columns' => array_keys($columns),
                'missing_required_columns' => $missing,
                'counts' => $counts,
                'blank_fields' => $blankCounts,
                'note' => 'Structural inspection only. No identity, curriculum, grade, or database validation was performed; no data was imported.',
            ];
        } finally {
            fclose($stream);
        }
    }

    /** Stream canonicalized values without retaining the source file in memory. */
    public function rows(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('CSV file is missing or unreadable.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('CSV file could not be opened.');
        }
        try {
            $headers = fgetcsv($stream, null, ',', '"', '');
            if ($headers === false || $headers === [null]) {
                throw new InvalidArgumentException('CSV file has no header row.');
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $normalized = array_map($this->normalize(...), $headers);
            if (count($normalized) !== count(array_unique($normalized))) {
                throw new InvalidArgumentException('CSV file has duplicate column names.');
            }
            $columns = [];
            foreach (self::ALIASES as $canonical => $aliases) {
                $matches = array_intersect($normalized, $aliases);
                if (count($matches) > 1) {
                    throw new InvalidArgumentException("More than one column maps to {$canonical}.");
                }
                if ($matches !== []) {
                    $columns[$canonical] = array_key_first($matches);
                }
            }
            $rowNo = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rowNo++;
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new InvalidArgumentException("CSV row {$rowNo} has a different column count.");
                }
                $data = [];
                foreach ($columns as $canonical => $index) {
                    $data[$canonical] = trim((string) $values[$index]);
                }
                yield $rowNo => $data;
            }
        } finally {
            fclose($stream);
        }
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))), '_');
    }
}

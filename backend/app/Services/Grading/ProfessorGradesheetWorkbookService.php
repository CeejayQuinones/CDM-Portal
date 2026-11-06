<?php

namespace App\Services\Grading;

use App\Models\GradeSheet;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProfessorGradesheetWorkbookService
{
    public const TEMPLATE_VERSION = 'CDM-PROFESSOR-GRADESHEET-1';

    private const ENTRY_SHEET = 'Grade Entry';

    private const METADATA_SHEET = '_CDM_Metadata';

    private const HEADER_ROW = 9;

    private const MAXIMUM_ROW = 10;

    private const DATA_START_ROW = 11;

    public function __construct(
        private GradingService $grading,
        private GradingWorkflowService $workflow,
        private ProfessorGradesheetImportService $imports,
    ) {}

    /** @return array{workbook: Spreadsheet, filename: string} */
    public function export(User $actor, GradeSheet $sheet, string $period): array
    {
        $context = $this->context($actor, $sheet, $period);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Colegio de Montalban')
            ->setTitle('Professor Grade Entry')
            ->setSubject(ucfirst($period).' offline score entry')
            ->setDescription('Authorized CDM Portal gradesheet template. Calculated results are informational and are not imported.');

        $entry = $spreadsheet->getActiveSheet();
        $entry->setTitle(self::ENTRY_SHEET);
        $metadata = new Worksheet($spreadsheet, self::METADATA_SHEET);
        $spreadsheet->addSheet($metadata);

        $this->writeEntrySheet($entry, $context);
        $this->writeMetadataSheet($metadata, $context);
        $spreadsheet->setActiveSheetIndex(0);

        $subject = preg_replace('/[^A-Za-z0-9_-]+/', '-', $context['class']['subject_code']) ?: 'subject';
        $section = preg_replace('/[^A-Za-z0-9_-]+/', '-', $context['class']['section']) ?: 'section';

        return [
            'workbook' => $spreadsheet,
            'filename' => strtolower("{$subject}-{$section}-{$period}-gradesheet.xlsx"),
        ];
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, GradeSheet $sheet, string $period, UploadedFile $file): array
    {
        $context = $this->context($actor, $sheet, $period);
        $reader = new Xlsx;
        $reader->setReadDataOnly(false);
        $workbook = $reader->load($file->getRealPath());

        try {
            $blocking = [];
            $issues = [];
            $titles = $workbook->getSheetNames();
            if ($titles !== [self::ENTRY_SHEET, self::METADATA_SHEET]) {
                $blocking[] = 'The workbook sheet structure is incompatible. Use a newly exported CDM Portal template.';
            }
            if (count($workbook->getDefinedNames()) > 0) {
                $blocking[] = 'Defined names are not permitted in an imported gradesheet.';
            }
            foreach ($workbook->getAllSheets() as $worksheet) {
                foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
                    $cell = $worksheet->getCell($coordinate);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $issues[] = ['type' => 'formula', 'cell' => $worksheet->getTitle().'!'.$coordinate, 'message' => 'Spreadsheet formulas are not accepted. Enter a numeric score or leave the cell blank.'];
                    }
                    if ($cell->hasHyperlink() && $cell->getHyperlink()->getUrl() !== '') {
                        $issues[] = ['type' => 'unsafe-content', 'cell' => $worksheet->getTitle().'!'.$coordinate, 'message' => 'Hyperlinks are not accepted in gradesheet imports.'];
                    }
                }
            }

            if (! in_array(self::ENTRY_SHEET, $titles, true) || ! in_array(self::METADATA_SHEET, $titles, true)) {
                return $this->previewResult($context, $blocking, $issues, [], 0, 0, 0);
            }

            $metadata = $this->readMetadata($workbook->getSheetByName(self::METADATA_SHEET));
            if (($metadata['template_version'] ?? null) !== self::TEMPLATE_VERSION) {
                $blocking[] = 'The workbook template version is unsupported. Export a new gradesheet and try again.';
            }

            foreach ([
                'grade_sheet_id' => $context['sheet']->id,
                'section_subject_id' => $context['sheet']->section_subject_id,
                'professor_id' => $context['sheet']->professor_id,
                'academic_year_id' => $context['academic_year_id'],
                'semester_id' => $context['semester_id'],
                'period' => $period,
                'sheet_version' => $context['sheet']->version,
            ] as $key => $expected) {
                if ($this->stringify($metadata[$key] ?? null) !== $this->stringify($expected)) {
                    $blocking[] = 'Workbook metadata does not match the selected class, term, Professor, or grading period.';
                    break;
                }
            }

            $currentStudents = collect($context['students'])->keyBy(fn ($student) => $this->stringify($student['enrollment_subject_id']));
            $metadataStudents = collect($metadata['students'] ?? [])->keyBy(fn ($student) => $this->stringify($student['enrollment_subject_id'] ?? null));
            $currentAssessments = collect($context['assessments'])->keyBy(fn ($assessment) => $this->stringify($assessment['id']));
            $metadataAssessments = collect($metadata['assessments'] ?? [])->keyBy(fn ($assessment) => $this->stringify($assessment['id'] ?? null));

            if ($metadataStudents->count() !== count($context['students'])) {
                $blocking[] = 'The workbook Student roster metadata is incomplete or duplicated.';
            }
            if ($metadataAssessments->count() !== count($context['assessments'])) {
                $blocking[] = 'The workbook assessment metadata is incomplete or duplicated.';
            }

            foreach ($currentAssessments as $id => $assessment) {
                $exported = $metadataAssessments->get($id);
                if (! $exported || (int) ($exported['version'] ?? 0) !== (int) $assessment['version']
                    || $this->stringify($exported['label'] ?? null) !== $this->stringify($assessment['label'])
                    || $this->stringify($exported['category'] ?? null) !== $this->stringify($assessment['category'])
                    || abs((float) ($exported['max_score'] ?? -1) - (float) $assessment['max_score']) > 0.00001
                    || $this->stringify($exported['status'] ?? null) !== 'active') {
                    $blocking[] = 'Assessment definitions changed after this workbook was exported. Export a new gradesheet.';
                    break;
                }
            }

            if (! $context['editable']) {
                $blocking[] = $context['locked_message'];
            }

            $entry = $workbook->getSheetByName(self::ENTRY_SHEET);
            $expectedLastColumn = 10 + count($context['assessments']);
            if (Coordinate::columnIndexFromString($entry->getHighestDataColumn()) !== $expectedLastColumn) {
                $blocking[] = 'The workbook contains missing, reordered, or extra columns.';
            }
            $this->validateHeaders($entry, $context, $blocking);

            $baseline = collect($metadata['scores'] ?? [])->keyBy(fn ($score) => $this->stringify($score['enrollment_subject_id'] ?? null).':'.$this->stringify($score['assessment_id'] ?? null));
            $seenStudents = [];
            $changes = [];
            $recognized = 0;
            $unchanged = 0;
            $unknown = 0;
            $invalid = count($issues);

            for ($row = self::DATA_START_ROW; $row <= $entry->getHighestDataRow(); $row++) {
                $studentId = trim((string) $entry->getCell('A'.$row)->getValue());
                $rowHasContent = false;
                for ($column = 2; $column <= $expectedLastColumn; $column++) {
                    if ($entry->getCell(Coordinate::stringFromColumnIndex($column).$row)->getValue() !== null) {
                        $rowHasContent = true;
                        break;
                    }
                }
                if ($studentId === '') {
                    if ($rowHasContent) {
                        $unknown++;
                        $issues[] = ['type' => 'unknown-row', 'cell' => 'A'.$row, 'message' => 'A populated row is missing its protected Student identifier.'];
                    }

                    continue;
                }
                if (isset($seenStudents[$studentId])) {
                    $unknown++;
                    $issues[] = ['type' => 'duplicate-student', 'cell' => 'A'.$row, 'message' => 'The workbook contains a duplicate Student row identifier.'];

                    continue;
                }
                $seenStudents[$studentId] = true;
                $student = $currentStudents->get($studentId);
                $exportedStudent = $metadataStudents->get($studentId);
                if (! $student || ! $exportedStudent) {
                    $unknown++;
                    $issues[] = ['type' => 'unknown-student', 'cell' => 'A'.$row, 'message' => 'This Student identifier is not part of the current authorized roster.'];

                    continue;
                }
                if ($this->stringify($entry->getCell('C'.$row)->getValue()) !== $this->stringify($student['student_number'])
                    || $this->stringify($entry->getCell('D'.$row)->getValue()) !== $this->stringify($student['student_name'])) {
                    $invalid++;
                    $issues[] = ['type' => 'identity-changed', 'cell' => 'C'.$row.':D'.$row, 'message' => 'Protected Student identity fields were altered.'];
                }
                $recognized++;

                foreach ($context['assessments'] as $assessment) {
                    $column = (int) $assessment['column_index'];
                    $coordinate = Coordinate::stringFromColumnIndex($column).$row;
                    $cell = $entry->getCell($coordinate);
                    $imported = $cell->getValue();
                    $current = $assessment['scores'][$studentId] ?? null;
                    $baselineScore = $baseline->get($studentId.':'.$assessment['id']);

                    if ($imported === null || $imported === '') {
                        $unchanged++;

                        continue;
                    }
                    if (! is_int($imported) && ! is_float($imported)) {
                        $invalid++;
                        $issues[] = ['type' => 'invalid-score', 'cell' => $coordinate, 'message' => 'Scores must be numeric. Blank cells leave existing scores unchanged.'];

                        continue;
                    }
                    $imported = (float) $imported;
                    if (! is_finite($imported) || $imported < 0 || $imported > (float) $assessment['max_score']) {
                        $invalid++;
                        $issues[] = ['type' => 'invalid-score', 'cell' => $coordinate, 'message' => 'The score must be between 0 and '.$assessment['max_score'].'.'];

                        continue;
                    }
                    if ($current !== null && abs((float) $current['value'] - $imported) < 0.00001) {
                        $unchanged++;

                        continue;
                    }

                    $baselineValue = $baselineScore['value'] ?? null;
                    $baselineVersion = $baselineScore['version'] ?? null;
                    $currentValue = $current['value'] ?? null;
                    $currentVersion = $current['version'] ?? null;
                    $conflict = $this->stringify($baselineVersion) !== $this->stringify($currentVersion)
                        || ! $this->sameNullableNumber($baselineValue, $currentValue);
                    $changes[] = [
                        'enrollment_subject_id' => (int) $studentId,
                        'assessment_id' => (int) $assessment['id'],
                        'student_number' => $student['student_number'],
                        'student_name' => $student['student_name'],
                        'assessment' => $assessment['label'],
                        'existing' => $currentValue,
                        'baseline_value' => $currentValue,
                        'baseline_version' => $currentVersion,
                        'imported' => $imported,
                        'status' => $conflict ? 'conflict' : 'ready',
                    ];
                }
            }

            $missingStudents = $currentStudents->keys()->reject(fn ($id) => isset($seenStudents[$id]))->values();
            foreach ($missingStudents as $id) {
                $student = $currentStudents->get($id);
                $issues[] = ['type' => 'missing-student', 'cell' => null, 'message' => $student['student_number'].' is missing from the imported workbook.'];
            }
            $conflicts = collect($changes)->where('status', 'conflict')->count();
            if ($invalid > 0 || $unknown > 0 || $missingStudents->isNotEmpty() || $conflicts > 0 || $issues !== []) {
                $blocking[] = 'Resolve every invalid, missing, unknown, unsafe, or conflicting entry before grades can be applied.';
            }

            $proposal = null;
            if ($blocking === [] && $changes !== []) {
                $proposal = $this->imports->issue($actor, $context, $changes, hash_file('sha256', $file->getRealPath()));
            }

            return $this->previewResult($context, $blocking, $issues, $changes, $recognized, $unchanged, $unknown, $invalid, $missingStudents->count(), $conflicts, $proposal);
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    /** @return array<string, mixed> */
    private function context(User $actor, GradeSheet $sheet, string $period): array
    {
        abort_unless(in_array($period, ['midterm', 'finals'], true), 422, 'A valid grading period is required.');
        $workspace = $this->grading->readWorkspace($actor, $sheet);
        $professor = $this->grading->assertOwnedSheetAccess($actor, $sheet);
        $assignment = $sheet->sectionSubject()->with(['section.academicYear', 'section.semester'])->firstOrFail();
        $assessments = $this->orderedAssessments($workspace['sheet']->assessments, $period);
        $students = collect($workspace['roster'])->map(fn ($student) => [
            'enrollment_subject_id' => (int) $student->enrollment_subject_id,
            'student_id' => (int) $student->student_id,
            'student_number' => $student->student_number,
            'student_name' => trim($student->last_name.', '.$student->first_name.' '.($student->middle_name ?? '')),
        ])->values()->all();
        $assessmentRows = [];
        foreach ($assessments as $index => $assessment) {
            $scores = [];
            foreach ($assessment->scores as $score) {
                $scores[$this->stringify($score->enrollment_subject_id)] = ['value' => (float) $score->score, 'version' => (int) $score->version];
            }
            $assessmentRows[] = [
                'id' => (int) $assessment->id,
                'version' => (int) $assessment->version,
                'label' => $assessment->label,
                'category' => $assessment->category,
                'max_score' => (float) $assessment->max_score,
                'status' => $assessment->status,
                'column_index' => 5 + $index,
                'scores' => $scores,
            ];
        }
        $readiness = $this->workflow->readiness($actor, $sheet);
        $results = collect($readiness['results'] ?? [])->keyBy(fn ($row) => $this->stringify($row['enrollment_subject_id']));
        $state = $workspace['schedule'][$period.'_state'] ?? ['state' => 'Unavailable', 'editable' => false];
        $editable = in_array($sheet->status, ['draft', 'returned'], true) && ($sheet->status === 'returned' || ($state['editable'] ?? false));

        return [
            'sheet' => $workspace['sheet'],
            'class' => $workspace['class'],
            'students' => $students,
            'assessments' => $assessmentRows,
            'results' => $results,
            'period' => $period,
            'professor_id' => (int) $professor->id,
            'academic_year_id' => (int) $assignment->section->academic_year_id,
            'semester_id' => (int) $assignment->section->semester_id,
            'editable' => $editable,
            'locked_message' => $sheet->status === 'returned' || ($state['editable'] ?? false)
                ? 'This grade sheet is locked for review or publication.'
                : ucfirst($period).' grade entry is '.($state['state'] ?? 'Unavailable').'.',
            'schedule' => $workspace['schedule'],
        ];
    }

    private function orderedAssessments(Collection $assessments, string $period): Collection
    {
        $ordered = $assessments->where('period', $period)->where('status', 'active')->sortBy(fn ($row) => sprintf('%08d:%08d', $row->display_order, $row->id));
        $groups = [];
        foreach ($ordered as $assessment) {
            $groups[$assessment->category] ??= [];
            $groups[$assessment->category][] = $assessment;
        }

        return collect(array_merge(...array_values($groups ?: [[]])));
    }

    /** @param array<string, mixed> $context */
    private function writeEntrySheet(Worksheet $sheet, array $context): void
    {
        $assessmentCount = count($context['assessments']);
        $lastColumn = 10 + $assessmentCount;
        $lastLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $assessmentStart = 5;
        $assessmentEnd = 4 + $assessmentCount;
        $rawColumn = 5 + $assessmentCount;
        $resultStart = $rawColumn + 1;

        $sheet->setCellValue('B1', 'COLEGIO DE MONTALBAN');
        $sheet->mergeCells('B1:'.$lastLetter.'1');
        $sheet->setCellValue('B2', 'PROFESSOR OFFLINE GRADE ENTRY');
        $sheet->mergeCells('B2:'.$lastLetter.'2');
        $sheet->setCellValue('B4', 'Academic Year');
        $sheet->setCellValue('C4', $context['class']['academic_year']);
        $sheet->setCellValue('E4', 'Semester');
        $sheet->setCellValue('F4', $context['class']['semester']);
        $sheet->setCellValue('H4', 'Period');
        $sheet->setCellValue('I4', ucfirst($context['period']));
        $sheet->setCellValue('B5', 'Subject');
        $sheet->setCellValue('C5', $context['class']['subject_code'].' — '.$context['class']['subject_name']);
        $sheet->mergeCells('C5:F5');
        $sheet->setCellValue('H5', 'Section');
        $sheet->setCellValue('I5', $context['class']['section']);
        $sheet->setCellValue('B6', 'Instructions');
        $sheet->setCellValue('C6', 'Edit only the pale-green assessment score cells. Leave a cell blank for no change; enter 0 for a zero score. Upload to CDM Portal for validation and review.');
        $sheet->mergeCells('C6:'.$lastLetter.'6');

        $sheet->setCellValue('A'.self::HEADER_ROW, 'Enrollment Subject ID');
        $sheet->setCellValue('B8', 'Student Information');
        $sheet->mergeCells('B8:D8');
        $sheet->setCellValue('B'.self::HEADER_ROW, 'No.');
        $sheet->setCellValue('C'.self::HEADER_ROW, 'Student Number');
        $sheet->setCellValue('D'.self::HEADER_ROW, 'Student Name');

        $categoryStart = $assessmentStart;
        $category = null;
        foreach ($context['assessments'] as $assessment) {
            $column = $assessment['column_index'];
            if ($category !== null && $category !== $assessment['category']) {
                $this->mergeCategory($sheet, $categoryStart, $column - 1, $category);
                $categoryStart = $column;
            }
            $category = $assessment['category'];
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->setCellValue($letter.self::HEADER_ROW, $assessment['label']);
            $sheet->setCellValue($letter.self::MAXIMUM_ROW, 'Maximum '.$assessment['max_score']);
        }
        if ($category !== null) {
            $this->mergeCategory($sheet, $categoryStart, $assessmentEnd, $category);
        }

        $rawLetter = Coordinate::stringFromColumnIndex($rawColumn);
        $sheet->setCellValue($rawLetter.'8', 'Raw Total');
        $sheet->mergeCells($rawLetter.'8:'.$rawLetter.self::MAXIMUM_ROW);
        $resultEnd = $lastColumn;
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($resultStart).'8', 'Calculated Results · Read Only');
        $sheet->mergeCells(Coordinate::stringFromColumnIndex($resultStart).'8:'.Coordinate::stringFromColumnIndex($resultEnd).'8');
        foreach (['Midterm', 'Finals', 'Final Grade', 'Equivalent', 'Remarks'] as $offset => $label) {
            $letter = Coordinate::stringFromColumnIndex($resultStart + $offset);
            $sheet->setCellValue($letter.self::HEADER_ROW, $label);
            $sheet->mergeCells($letter.self::HEADER_ROW.':'.$letter.self::MAXIMUM_ROW);
        }

        foreach ($context['students'] as $index => $student) {
            $row = self::DATA_START_ROW + $index;
            $studentKey = $this->stringify($student['enrollment_subject_id']);
            $sheet->setCellValueExplicit('A'.$row, $studentKey, DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$row, $index + 1);
            $sheet->setCellValueExplicit('C'.$row, $student['student_number'], DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$row, $student['student_name']);
            $rawTotal = 0;
            foreach ($context['assessments'] as $assessment) {
                $letter = Coordinate::stringFromColumnIndex($assessment['column_index']);
                $score = $assessment['scores'][$studentKey]['value'] ?? null;
                if ($score !== null) {
                    $sheet->setCellValue($letter.$row, $score);
                    $rawTotal += $score;
                }
                $validation = $sheet->getCell($letter.$row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_DECIMAL)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setOperator(DataValidation::OPERATOR_BETWEEN)
                    ->setAllowBlank(true)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('Invalid score')
                    ->setError('Enter a number from 0 through '.$assessment['max_score'].', or leave blank for unchanged.')
                    ->setFormula1('0')
                    ->setFormula2((string) $assessment['max_score']);
            }
            $sheet->setCellValue($rawLetter.$row, $rawTotal);
            $result = $context['results']->get($studentKey, []);
            foreach (['midterm_grade', 'finals_grade', 'final_grade', 'grade_point', 'remarks'] as $offset => $field) {
                $value = $result[$field] ?? null;
                if ($value !== null) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($resultStart + $offset).$row, $value);
                }
            }
        }

        $lastRow = max(self::DATA_START_ROW, self::DATA_START_ROW + count($context['students']) - 1);
        $sheet->getColumnDimension('A')->setVisible(false);
        $sheet->getColumnDimension('B')->setWidth(7);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(30);
        for ($column = $assessmentStart; $column <= $assessmentEnd; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(16);
        }
        for ($column = $rawColumn; $column <= $lastColumn; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column === $lastColumn ? 18 : 14);
        }
        $sheet->getStyle('B1:'.$lastLetter.'2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF14532D');
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('B8:'.$lastLetter.self::MAXIMUM_ROW)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDF4E5');
        $sheet->getStyle('B8:'.$lastLetter.self::MAXIMUM_ROW)->getFont()->setBold(true)->getColor()->setARGB('FF14532D');
        $sheet->getStyle('B8:'.$lastLetter.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF94A3B8');
        $sheet->getStyle('B8:'.$lastLetter.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        if ($assessmentCount > 0 && count($context['students']) > 0) {
            $editableRange = Coordinate::stringFromColumnIndex($assessmentStart).self::DATA_START_ROW.':'.Coordinate::stringFromColumnIndex($assessmentEnd).$lastRow;
            $sheet->getStyle($editableRange)
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEAF8EF');
            // Keep this as the final style operation on score cells. Excel honors
            // the serialized cell XF, so unlock the complete editable range only
            // after fills, borders, alignment, and number styles are finalized.
            $sheet->getStyle($editableRange)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
            $sheet->setSelectedCell(Coordinate::stringFromColumnIndex($assessmentStart).self::DATA_START_ROW);
        }
        $sheet->freezePane('E'.self::DATA_START_ROW);
        $sheet->setAutoFilter('B'.self::HEADER_ROW.':'.$lastLetter.$lastRow);
        $sheet->getProtection()
            ->setPassword('cdm-portal-template')
            ->setSheet(true)
            ->setSort(true)
            ->setAutoFilter(true)
            // In OOXML these flags mean that selection is prohibited. Permit
            // score-cell selection and keep protected identity/result cells out
            // of the normal Tab/Enter navigation path.
            ->setSelectUnlockedCells(false)
            ->setSelectLockedCells(true);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);

        $seal = base_path('../CDM_Frontend/src/assets/styles/images/cdm-seal.png');
        if (is_readable($seal)) {
            $drawing = new Drawing;
            $drawing->setName('Colegio de Montalban seal')->setDescription('Colegio de Montalban seal')->setPath($seal)->setHeight(64)->setCoordinates('B1')->setOffsetX(6)->setOffsetY(3)->setWorksheet($sheet);
            $sheet->getRowDimension(1)->setRowHeight(52);
        }
    }

    private function mergeCategory(Worksheet $sheet, int $start, int $end, string $category): void
    {
        $startLetter = Coordinate::stringFromColumnIndex($start);
        $endLetter = Coordinate::stringFromColumnIndex($end);
        $sheet->setCellValue($startLetter.'8', $category);
        if ($start !== $end) {
            $sheet->mergeCells($startLetter.'8:'.$endLetter.'8');
        }
    }

    /** @param array<string, mixed> $context */
    private function writeMetadataSheet(Worksheet $sheet, array $context): void
    {
        $payload = $this->metadataPayload($context);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $signature = hash_hmac('sha256', $json, (string) config('app.key'));
        $chunks = str_split($json, 30000);
        $sheet->setCellValue('A1', 'template_version');
        $sheet->setCellValue('B1', self::TEMPLATE_VERSION);
        $sheet->setCellValue('A2', 'signature');
        $sheet->setCellValueExplicit('B2', $signature, DataType::TYPE_STRING);
        $sheet->setCellValue('A3', 'payload_chunks');
        $sheet->setCellValue('B3', count($chunks));
        foreach ($chunks as $index => $chunk) {
            $sheet->setCellValue('A'.($index + 4), 'payload_'.($index + 1));
            $sheet->setCellValueExplicit('B'.($index + 4), $chunk, DataType::TYPE_STRING);
        }
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(80);
        $sheet->getProtection()->setPassword('cdm-portal-metadata')->setSheet(true);
        $sheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
    }

    /** @param array<string, mixed> $context */
    private function metadataPayload(array $context): array
    {
        $scores = [];
        foreach ($context['assessments'] as $assessment) {
            foreach ($assessment['scores'] as $studentId => $score) {
                $scores[] = [
                    'enrollment_subject_id' => (int) $studentId,
                    'assessment_id' => (int) $assessment['id'],
                    'value' => $score['value'],
                    'version' => $score['version'],
                ];
            }
        }

        return [
            'template_version' => self::TEMPLATE_VERSION,
            'grade_sheet_id' => (int) $context['sheet']->id,
            'section_subject_id' => (int) $context['sheet']->section_subject_id,
            'professor_id' => (int) $context['sheet']->professor_id,
            'academic_year_id' => $context['academic_year_id'],
            'academic_year' => $context['class']['academic_year'],
            'semester_id' => $context['semester_id'],
            'semester' => $context['class']['semester'],
            'period' => $context['period'],
            'sheet_version' => (int) $context['sheet']->version,
            'students' => $context['students'],
            'assessments' => collect($context['assessments'])->map(fn ($assessment) => collect($assessment)->except('scores')->all())->values()->all(),
            'scores' => $scores,
        ];
    }

    /** @return array<string, mixed> */
    private function readMetadata(Worksheet $sheet): array
    {
        $template = $this->stringify($sheet->getCell('B1')->getValue());
        $signature = $this->stringify($sheet->getCell('B2')->getValue());
        $count = (int) $sheet->getCell('B3')->getValue();
        abort_unless($count >= 1 && $count <= 1000, 422, 'Workbook metadata is missing or invalid.');
        $json = '';
        for ($index = 0; $index < $count; $index++) {
            $json .= (string) $sheet->getCell('B'.($index + 4))->getValue();
        }
        abort_unless(hash_equals(hash_hmac('sha256', $json, (string) config('app.key')), $signature), 422, 'Workbook metadata was altered or is not an authentic CDM Portal export.');
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($payload) && $this->stringify($payload['template_version'] ?? null) === $template, 422, 'Workbook metadata is inconsistent.');

        return $payload;
    }

    /** @param array<string, mixed> $context @param array<int, string> $blocking */
    private function validateHeaders(Worksheet $sheet, array $context, array &$blocking): void
    {
        foreach (['B' => 'No.', 'C' => 'Student Number', 'D' => 'Student Name'] as $column => $label) {
            if ($this->stringify($sheet->getCell($column.self::HEADER_ROW)->getValue()) !== $label) {
                $blocking[] = 'Protected identity columns were missing, reordered, or renamed.';
                break;
            }
        }
        foreach ($context['assessments'] as $assessment) {
            $letter = Coordinate::stringFromColumnIndex($assessment['column_index']);
            if ($this->stringify($sheet->getCell($letter.self::HEADER_ROW)->getValue()) !== $this->stringify($assessment['label'])
                || $this->stringify($sheet->getCell($letter.self::MAXIMUM_ROW)->getValue()) !== 'Maximum '.$this->stringify($assessment['max_score'])) {
                $blocking[] = 'Assessment columns were missing, reordered, renamed, or changed.';
                break;
            }
        }
        $rawColumn = Coordinate::stringFromColumnIndex(5 + count($context['assessments']));
        if ($this->stringify($sheet->getCell($rawColumn.'8')->getValue()) !== 'Raw Total') {
            $blocking[] = 'The protected calculated-result structure was changed.';
        }
    }

    /** @return array<string, mixed> */
    private function previewResult(array $context, array $blocking, array $issues, array $changes, int $recognized, int $unchanged, int $unknown, int $invalid = 0, int $missing = 0, int $conflicts = 0, ?array $proposal = null): array
    {
        $canApply = $proposal !== null && $blocking === [] && $changes !== [];

        return [
            'template_version' => self::TEMPLATE_VERSION,
            'period' => $context['period'],
            'summary' => [
                'recognized_students' => $recognized,
                'proposed_changes' => count($changes),
                'unchanged_scores' => $unchanged,
                'invalid_cells' => $invalid,
                'missing_identifiers' => $missing,
                'unknown_identifiers' => $unknown,
                'conflicts' => $conflicts,
            ],
            'changes' => collect($changes)->map(fn (array $change) => collect($change)->except(['baseline_value', 'baseline_version'])->all())->values()->all(),
            'issues' => array_values($issues),
            'blocking_errors' => array_values(array_unique($blocking)),
            'can_apply' => $canApply,
            'proposal_token' => $proposal['proposal_token'] ?? null,
            'proposal_expires_at' => $proposal['proposal_expires_at'] ?? null,
            'apply_message' => $canApply
                ? 'Ready to apply. Imported scores will be saved as grading drafts and will not be submitted to the Registrar.'
                : ($changes === [] && $blocking === [] ? 'No score changes are available to apply.' : 'Resolve the blocking issues and preview the workbook again.'),
        ];
    }

    private function sameNullableNumber(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return abs((float) $left - (float) $right) < 0.00001;
    }

    private function stringify(mixed $value): string
    {
        return $value === null ? '' : trim((string) $value);
    }
}

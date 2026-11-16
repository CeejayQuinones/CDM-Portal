<?php

namespace App\Services\StudentRecords;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRow;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Provisional identity/reference matching only. This service never creates Portal records. */
class LegacyMigrationMatcher
{
    public function match(LegacyMigrationBatch $batch): array
    {
        if (! in_array($batch->status, ['staged', 'matched', 'preflighted'], true)) {
            throw new InvalidArgumentException('Only staged, matched, or unapproved preflighted batches can be matched.');
        }
        $duplicates = $batch->rows()->whereNotNull('source_key_sha256')
            ->select('source_key_sha256')->groupBy('source_key_sha256')
            ->havingRaw('COUNT(*) > 1')->pluck('source_key_sha256')->flip();

        $batch->rows()->orderBy('id')->chunkById(200, function ($rows) use ($batch, $duplicates): void {
            DB::transaction(function () use ($rows, $batch, $duplicates): void {
                foreach ($rows as $row) {
                    [$status, $reason, $refs] = $this->resolve($batch->entity, $row, isset($duplicates[$row->source_key_sha256]));
                    $row->update(['status' => $status, 'reason_code' => $reason, 'resolved_refs' => $refs]);
                }
            });
        });
        $batch->update(['status' => 'matched']);

        return $this->report($batch);
    }

    public function report(LegacyMigrationBatch $batch): array
    {
        return [
            'batch_id' => $batch->id,
            'entity' => $batch->entity,
            'source_sha256' => $batch->source_sha256,
            'status' => $batch->status,
            'row_count' => $batch->row_count,
            'approved_by' => $batch->approved_by,
            'approved_at' => $batch->approved_at?->toIso8601String(),
            'by_status' => $batch->rows()->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'by_reason' => $batch->rows()->whereNotNull('reason_code')->select('reason_code')->selectRaw('COUNT(*) as total')->groupBy('reason_code')->pluck('total', 'reason_code')->all(),
            'note' => match ($batch->status) {
                'preflighted' => 'Existing importer validators passed for ready rows. No Portal records were created.',
                'approved' => 'Registrar approved the reconciled batch. A separate controlled commit is required.',
                'importing' => 'Controlled commit is in progress; completed chunks are official records.',
                'imported' => 'Controlled commit completed; historical grades are verified and student-visible.',
                default => 'Provisional reference matches only. No full importer validation, approval, or Portal record creation has occurred.',
            },
        ];
    }

    private function resolve(string $entity, LegacyMigrationRow $row, bool $duplicate): array
    {
        $data = $row->payload;
        if (trim($data['student_number'] ?? '') === '') {
            return ['needs_review', 'missing_student_number', null];
        }
        if ($duplicate) {
            return ['needs_review', 'duplicate_source_key', null];
        }

        return $entity === 'students' ? $this->student($data) : $this->grade($data);
    }

    private function student(array $data): array
    {
        if (Student::where('student_number', $data['student_number'])->exists()) {
            return ['needs_review', 'student_already_exists', null];
        }
        foreach (['first_name', 'last_name', 'course_code', 'curriculum_code', 'year_level', 'student_status', 'admission_date', 'gender'] as $field) {
            if (trim($data[$field] ?? '') === '') {
                return ['needs_review', 'missing_required_roster_field', null];
            }
        }
        $course = Course::where('course_code', $data['course_code'])->first();
        if (! $course || $course->course_code !== $data['course_code']) {
            return ['needs_review', 'unknown_course', null];
        }
        $curriculum = Curriculum::where('course_id', $course->id)->where('curriculum_code', $data['curriculum_code'])->first();
        if (! $curriculum || $curriculum->curriculum_code !== $data['curriculum_code']) {
            return ['needs_review', 'unknown_curriculum', null];
        }

        return ['matched', null, ['course_id' => $course->id, 'curriculum_id' => $curriculum->id]];
    }

    private function grade(array $data): array
    {
        foreach (['academic_year', 'semester', 'subject_code', 'final_grade', 'remarks', 'credited_units'] as $field) {
            if (trim($data[$field] ?? '') === '') {
                return ['needs_review', 'missing_grade_field', null];
            }
        }
        $number = trim($data['student_number']);
        $student = Student::where('student_number', $number)->first();
        if ($student && $student->student_number !== $number) {
            return ['needs_review', 'ambiguous_student', null];
        }
        $stagedKey = hash('sha256', mb_strtolower($number));
        $staged = LegacyMigrationRow::query()->where('source_key_sha256', $stagedKey)
            ->where('status', 'matched')
            ->whereHas('batch', fn ($query) => $query->where('entity', 'students')->where('status', 'matched'))
            ->limit(2)->get();
        if (($student && $staged->isNotEmpty()) || $staged->count() > 1) {
            return ['needs_review', 'ambiguous_student', null];
        }
        if (! $student && $staged->isEmpty()) {
            return ['needs_review', 'unknown_student', null];
        }
        $curriculumId = $student?->curriculum_id;
        if (! $student) {
            $stagedData = $staged->first()->payload;
            if (($stagedData['student_number'] ?? null) !== $number) {
                return ['needs_review', 'ambiguous_student', null];
            }
            $course = Course::where('course_code', $stagedData['course_code'] ?? '')->first();
            $curriculum = $course ? Curriculum::where('course_id', $course->id)->where('curriculum_code', $stagedData['curriculum_code'] ?? '')->first() : null;
            $curriculumId = $curriculum?->id;
        }
        if (! $curriculumId) {
            return ['needs_review', 'unknown_curriculum', null];
        }
        $year = AcademicYear::where('school_year', $data['academic_year'])->first();
        $semester = Semester::where('semester_name', $data['semester'])->first();
        if (! $year || ! $semester || $year->school_year !== $data['academic_year'] || $semester->semester_name !== $data['semester']) {
            return ['needs_review', 'unknown_term', null];
        }
        $subjects = Subject::where('curriculum_id', $curriculumId)->where('subject_code', $data['subject_code'])->limit(2)->get();
        if ($subjects->count() !== 1 || $subjects->first()->subject_code !== $data['subject_code']) {
            return ['needs_review', 'unknown_or_ambiguous_subject', null];
        }

        return ['matched', null, [
            'student_id' => $student?->id,
            'staged_student_row_id' => $student ? null : $staged->first()->id,
            'curriculum_id' => $curriculumId,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'subject_id' => $subjects->first()->id,
        ]];
    }
}

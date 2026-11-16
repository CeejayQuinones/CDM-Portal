<?php

namespace Tests\Feature;

use App\Models\HistoricalGradeRecord;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRow;
use App\Models\Student;
use App\Services\StudentRecords\LegacyMigrationApprovalService;
use App\Services\StudentRecords\LegacyMigrationCommitService;
use App\Services\StudentRecords\LegacyMigrationMatcher;
use App\Services\StudentRecords\LegacyMigrationPreflight;
use App\Services\StudentRecords\LegacyMigrationStagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class LegacyMigrationStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_export_is_encrypted_staged_and_exactly_matched_without_creating_student(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-stage');
        $path = $this->csv([
            ['Student Number', 'First Name', 'Last Name', 'Course Code', 'Curriculum Code', 'Year Level', 'Student Status', 'Date of Admission', 'Sex'],
            ['26-90001', 'Ana', 'Santos', $f['course']->course_code, $f['curriculum']->curriculum_code, '2', 'regular', '2025-06-01', 'Female'],
        ]);
        try {
            $before = Student::count();
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
            $this->assertSame('staged', $batch->status);
            $this->assertSame(1, $batch->row_count);
            $this->assertStringNotContainsString('Santos', DB::table('legacy_migration_rows')->value('payload'));
            $this->assertSame('Santos', $batch->rows()->firstOrFail()->payload['last_name']);
            $report = app(LegacyMigrationMatcher::class)->match($batch);
            $this->assertSame(1, $report['by_status']['matched']);
            $this->assertSame($f['curriculum']->id, $batch->rows()->firstOrFail()->resolved_refs['curriculum_id']);
            $this->assertSame($before, Student::count());
            $this->assertSame(0, HistoricalGradeRecord::count());
            $this->expectException(InvalidArgumentException::class);
            app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
        } finally {
            unlink($path);
        }
    }

    public function test_grade_matching_uses_student_curriculum_and_reports_unknown_subject(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-grade');
        $path = $this->csv([
            ['Student Number', 'School Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits'],
            [$f['student']->student_number, $f['year']->school_year, $f['semester']->semester_name, $f['subjects'][0]->subject_code, '1.75', 'Passed', '3'],
            [$f['student']->student_number, $f['year']->school_year, $f['semester']->semester_name, 'UNKNOWN', '1.75', 'Passed', '3'],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'grades', 'test-operator');
            $report = app(LegacyMigrationMatcher::class)->match($batch);
            $this->assertSame(1, $report['by_status']['matched']);
            $this->assertSame(1, $report['by_reason']['unknown_or_ambiguous_subject']);
            $this->assertSame(0, HistoricalGradeRecord::count());
            $this->assertSame(2, LegacyMigrationRow::count());
        } finally {
            unlink($path);
        }
    }

    public function test_preflight_requires_roster_source_reference_and_does_not_create_accounts(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-preflight-roster');
        $path = $this->csv([
            ['Student Number', 'First Name', 'Last Name', 'Course Code', 'Curriculum Code', 'Year Level', 'Student Status', 'Date of Admission', 'Sex', 'Record Reference'],
            ['26-90001', 'Ana', 'Santos', $f['course']->course_code, $f['curriculum']->curriculum_code, '2', 'regular', '2025-06-01', 'Female', ''],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($batch);
            $before = Student::count();
            $report = app(LegacyMigrationPreflight::class)->run($batch);
            $this->assertSame('preflighted', $report['status']);
            $this->assertSame(1, $report['by_reason']['roster_validation_failed']);
            $this->assertSame($before, Student::count());
        } finally {
            unlink($path);
        }
    }

    public function test_grade_preflight_requires_explicit_official_result_and_source_reference(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-preflight-grade');
        $path = $this->csv([
            ['Student Number', 'School Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits', 'Official Result', 'Source Reference'],
            [$f['student']->student_number, $f['year']->school_year, $f['semester']->semester_name, $f['subjects'][0]->subject_code, '1.75', 'Passed', '3', '', ''],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'grades', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($batch);
            $report = app(LegacyMigrationPreflight::class)->run($batch);
            $this->assertSame(1, $report['by_reason']['missing_official_result_or_source']);
            $this->assertSame(0, HistoricalGradeRecord::count());
        } finally {
            unlink($path);
        }
    }

    public function test_grade_preflight_marks_explicit_valid_result_ready_without_importing(): void
    {
        $f = EnrollmentAcademicFixture::create('lgready');
        $path = $this->csv([
            ['Student Number', 'School Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits', 'Official Result', 'Source Reference'],
            [$f['student']->student_number, $f['year']->school_year, $f['semester']->semester_name, $f['subjects'][0]->subject_code, '1.75', 'Passed', '3', 'Passed', 'Access register 123'],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'grades', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($batch);
            $report = app(LegacyMigrationPreflight::class)->run($batch);
            $this->assertSame(1, $report['by_status']['ready']);
            $this->assertSame(0, HistoricalGradeRecord::count());
        } finally {
            unlink($path);
        }
    }

    public function test_grade_preflight_does_not_treat_staged_student_as_imported(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-preflight-staged');
        $students = $this->csv([
            ['Student Number', 'First Name', 'Last Name', 'Course Code', 'Curriculum Code', 'Year Level', 'Student Status', 'Date of Admission', 'Sex', 'Record Reference'],
            ['26-90001', 'Ana', 'Santos', $f['course']->course_code, $f['curriculum']->curriculum_code, '2', 'regular', '2025-06-01', 'Female', 'Access register 123'],
        ]);
        $grades = $this->csv([
            ['Student Number', 'School Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits', 'Official Result', 'Source Reference'],
            ['26-90001', $f['year']->school_year, $f['semester']->semester_name, $f['subjects'][0]->subject_code, '1.75', 'Passed', '3', 'Passed', 'Access register 123'],
        ]);
        try {
            $studentBatch = app(LegacyMigrationStagingService::class)->stage($students, 'students', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($studentBatch);
            $gradeBatch = app(LegacyMigrationStagingService::class)->stage($grades, 'grades', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($gradeBatch);
            $report = app(LegacyMigrationPreflight::class)->run($gradeBatch);
            $this->assertSame(1, $report['by_reason']['student_not_imported']);
            $this->assertSame(0, HistoricalGradeRecord::count());
        } finally {
            unlink($students);
            unlink($grades);
        }
    }

    public function test_approved_student_then_grade_batches_commit_once_with_provenance(): void
    {
        $f = EnrollmentAcademicFixture::create('lgflow');
        $students = $this->csv([
            ['Student Number', 'First Name', 'Last Name', 'Course Code', 'Curriculum Code', 'Year Level', 'Student Status', 'Date of Admission', 'Sex', 'Record Reference'],
            ['26-90001', 'Ana', 'Santos', $f['course']->course_code, $f['curriculum']->curriculum_code, '1', 'regular', '2026-08-01', 'Female', 'Access register 123'],
        ]);
        $grades = $this->csv([
            ['Student Number', 'School Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits', 'Official Result', 'Source Reference'],
            ['26-90001', $f['year']->school_year, $f['semester']->semester_name, $f['subjects'][0]->subject_code, '1.75', 'Passed', '3', 'Passed', 'Access grade register 123'],
        ]);
        try {
            $staging = app(LegacyMigrationStagingService::class);
            $matcher = app(LegacyMigrationMatcher::class);
            $preflight = app(LegacyMigrationPreflight::class);
            $approval = app(LegacyMigrationApprovalService::class);
            $committer = app(LegacyMigrationCommitService::class);
            $studentBatch = $staging->stage($students, 'students', 'test-operator');
            $matcher->match($studentBatch);
            $this->assertSame(1, $preflight->run($studentBatch)['by_status']['ready']);
            $this->assertSame(0, Student::where('student_number', '26-90001')->count());
            $this->assertSame(1, $approval->approve($studentBatch, $f['registrar'], $this->approvalInput($studentBatch))->row_count);
            $this->assertSame('imported', $committer->commit($studentBatch->fresh())['status']);
            $student = Student::where('student_number', '26-90001')->firstOrFail();
            $this->assertSame('inactive', $student->user->status);
            $this->assertSame('imported', $studentBatch->rows()->firstOrFail()->status);

            $gradeBatch = $staging->stage($grades, 'grades', 'test-operator');
            $matcher->match($gradeBatch);
            $this->assertSame(1, $preflight->run($gradeBatch)['by_status']['ready']);
            $approval->approve($gradeBatch, $f['registrar'], $this->approvalInput($gradeBatch));
            $this->assertSame('imported', $committer->commit($gradeBatch->fresh())['status']);
            $record = HistoricalGradeRecord::where('student_id', $student->id)->firstOrFail();
            $this->assertSame('legacy_import', $record->source_type);
            $this->assertSame('verified', $record->verification_status);
            $this->assertSame($f['registrar']->id, $record->verified_by);
            $this->expectException(InvalidArgumentException::class);
            $committer->commit($gradeBatch->fresh());
        } finally {
            unlink($students);
            unlink($grades);
        }
    }

    public function test_batch_approval_rejects_exceptions_and_wrong_reconciliation(): void
    {
        $f = EnrollmentAcademicFixture::create('lgdeny');
        $path = $this->csv([
            ['Student Number', 'First Name', 'Last Name', 'Course Code', 'Curriculum Code', 'Year Level', 'Student Status', 'Date of Admission', 'Sex', 'Record Reference'],
            ['26-90001', 'Ana', 'Santos', $f['course']->course_code, $f['curriculum']->curriculum_code, '1', 'regular', '2026-08-01', 'Female', ''],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($batch);
            app(LegacyMigrationPreflight::class)->run($batch);
            $this->expectException(ValidationException::class);
            app(LegacyMigrationApprovalService::class)->approve($batch, $f['registrar'], $this->approvalInput($batch));
        } finally {
            unlink($path);
            $this->assertSame(0, Student::where('student_number', '26-90001')->count());
        }
    }

    public function test_grade_may_reference_only_a_provisionally_matched_staged_student(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-staged-link');
        $students = $this->csv([
            ['Student Number', 'First Name', 'Last Name', 'Course Code', 'Curriculum Code', 'Year Level', 'Student Status', 'Date of Admission', 'Sex'],
            ['26-90001', 'Ana', 'Santos', $f['course']->course_code, $f['curriculum']->curriculum_code, '2', 'regular', '2025-06-01', 'Female'],
        ]);
        $grades = $this->csv([
            ['Student Number', 'School Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits'],
            ['26-90001', $f['year']->school_year, $f['semester']->semester_name, $f['subjects'][0]->subject_code, '1.75', 'Passed', '3'],
        ]);
        try {
            $studentBatch = app(LegacyMigrationStagingService::class)->stage($students, 'students', 'test-operator');
            $gradeBatch = app(LegacyMigrationStagingService::class)->stage($grades, 'grades', 'test-operator');
            $matcher = app(LegacyMigrationMatcher::class);
            $this->assertSame(1, $matcher->match($gradeBatch)['by_reason']['unknown_student']);
            $this->assertSame(1, $matcher->match($studentBatch)['by_status']['matched']);
            $this->assertSame(1, $matcher->match($gradeBatch)['by_status']['matched']);
            $this->assertSame($studentBatch->rows()->firstOrFail()->id, $gradeBatch->rows()->firstOrFail()->resolved_refs['staged_student_row_id']);
            $this->assertDatabaseMissing('students', ['student_number' => '26-90001']);
        } finally {
            unlink($students);
            unlink($grades);
        }
    }

    public function test_duplicate_grade_keys_and_missing_fields_remain_for_review(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-duplicate');
        $path = $this->csv([
            ['Student No', 'Academic Year', 'Semester', 'Subject Code', 'Final Grade', 'Remarks', 'Credits'],
            [$f['student']->student_number, $f['year']->school_year, $f['semester']->semester_name, 'AC1', '1.75', 'Passed', '3'],
            [$f['student']->student_number, $f['year']->school_year, $f['semester']->semester_name, 'AC1', '1.50', 'Passed', '3'],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'grades', 'test-operator');
            $report = app(LegacyMigrationMatcher::class)->match($batch);
            $this->assertSame(2, $report['by_reason']['duplicate_source_key']);
            $this->assertSame(2, $report['by_status']['needs_review']);
        } finally {
            unlink($path);
        }
    }

    public function test_invalid_export_does_not_leave_partial_batch(): void
    {
        $path = $this->csv([
            ['Student Number', 'First Name', 'Last Name'],
            ['26-90001', 'Ana'],
        ]);
        try {
            $this->expectException(InvalidArgumentException::class);
            app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
        } finally {
            unlink($path);
            $this->assertSame(0, LegacyMigrationBatch::count());
        }
    }

    public function test_interrupted_loading_batch_resumes_from_the_same_export(): void
    {
        $path = $this->csv([
            ['Student Number', 'First Name', 'Last Name'],
            ['26-90001', 'Ana', 'Santos'],
            ['26-90002', 'Ben', 'Cruz'],
        ]);
        try {
            $batch = LegacyMigrationBatch::create([
                'entity' => 'students', 'source_sha256' => hash_file('sha256', $path),
                'operator_reference' => 'test-operator', 'status' => 'loading',
            ]);
            LegacyMigrationRow::create([
                'batch_id' => $batch->id, 'source_row' => 2,
                'source_key_sha256' => hash('sha256', '26-90001'),
                'payload' => ['student_number' => '26-90001', 'first_name' => 'Ana', 'last_name' => 'Santos'],
                'status' => 'staged',
            ]);
            $resumed = app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
            $this->assertSame($batch->id, $resumed->id);
            $this->assertSame('staged', $resumed->status);
            $this->assertSame(2, $resumed->rows()->count());
        } finally {
            unlink($path);
        }
    }

    public function test_only_registrar_can_read_aggregate_staging_report(): void
    {
        $f = EnrollmentAcademicFixture::create('legacy-access');
        $path = $this->csv([
            ['Student Number', 'First Name', 'Last Name'],
            ['26-90001', 'Ana', 'Santos'],
        ]);
        try {
            $batch = app(LegacyMigrationStagingService::class)->stage($path, 'students', 'test-operator');
            app(LegacyMigrationMatcher::class)->match($batch);
            Sanctum::actingAs($f['registrar']);
            $this->getJson('/api/registrar/legacy-migration/batches')->assertOk()
                ->assertJsonPath('data.0.row_count', 1)
                ->assertDontSee('Santos');
            $this->getJson('/api/registrar/legacy-migration/batches/'.$batch->id)->assertOk()
                ->assertJsonPath('data.by_reason.missing_required_roster_field', 1)
                ->assertDontSee('26-90001');
            $this->postJson('/api/registrar/legacy-migration/batches/'.$batch->id.'/approve', [])->assertStatus(428);
            Sanctum::actingAs($f['user']);
            $this->getJson('/api/registrar/legacy-migration/batches')->assertForbidden();
            $this->getJson('/api/registrar/legacy-migration/batches/'.$batch->id)->assertForbidden();
            $this->postJson('/api/registrar/legacy-migration/batches/'.$batch->id.'/approve', [])->assertForbidden();
        } finally {
            unlink($path);
        }
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-stage-');
        $out = fopen($path, 'wb');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);

        return $path;
    }

    private function approvalInput(LegacyMigrationBatch $batch): array
    {
        return [
            'source_sha256' => $batch->source_sha256,
            'reconciled_row_count' => $batch->row_count,
            'approval_reference' => 'Signed test reconciliation report',
            'backup_reference' => 'Verified disposable test backup',
        ];
    }
}

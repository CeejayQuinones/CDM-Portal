<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeAuditEvent;
use App\Models\GradePeriodSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\User;
use App\Services\Grading\ProfessorGradesheetWorkbookService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;
use ZipArchive;

class ProfessorGradesheetWorkbookTest extends TestCase
{
    private array $fixture;

    private SectionSubject $assignment;

    private EnrollmentSubject $firstEnrollmentSubject;

    private EnrollmentSubject $secondEnrollmentSubject;

    private int $sheetId;

    private array $assessments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('excel');
        $this->assignment = SectionSubject::create([
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
        ]);
        $this->firstEnrollmentSubject = $this->enroll($this->fixture['student']);

        $secondUser = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::STUDENT])->id]);
        $secondProfile = $secondUser->profile()->create(['first_name' => 'Beta', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $secondStudent = Student::create([
            'user_id' => $secondUser->id,
            'user_profile_id' => $secondProfile->id,
            'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id,
            'student_number' => 'EXCEL-0002',
            'admission_date' => now()->toDateString(),
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
        $this->secondEnrollmentSubject = $this->enroll($secondStudent);

        GradePeriodSchedule::create([
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'midterm_opens_at' => now()->subHour(),
            'midterm_deadline' => now()->addHour(),
            'finals_opens_at' => now()->subHour(),
            'finals_deadline' => now()->addHour(),
            'created_by' => $this->fixture['registrar']->id,
            'updated_by' => $this->fixture['registrar']->id,
        ]);

        Sanctum::actingAs($this->fixture['professorUser']);
        $workspace = $this->withHeader('X-CDM-Client', 'web')
            ->postJson('/api/grading/classes/'.$this->assignment->id.'/workspace')
            ->assertOk()
            ->json('data');
        $this->sheetId = $workspace['sheet']['id'];
        foreach ([
            ['label' => 'Quiz 1', 'category' => 'Quiz', 'max_score' => 20, 'display_order' => 1],
            ['label' => 'Activity 1', 'category' => 'Activity', 'max_score' => 50, 'display_order' => 2],
        ] as $payload) {
            $this->assessments[] = $this->withHeader('X-CDM-Client', 'web')
                ->postJson('/api/grading/sheets/'.$this->sheetId.'/assessments', ['period' => 'midterm'] + $payload)
                ->assertCreated()
                ->json('data');
        }
        foreach ($this->assessments as $index => $assessment) {
            $this->withHeader('X-CDM-Client', 'web')->putJson('/api/grading/sheets/'.$this->sheetId.'/scores', [
                'assessment_id' => $assessment['id'],
                'scores' => [
                    ['enrollment_subject_id' => $this->firstEnrollmentSubject->id, 'score' => 10 + $index],
                    ['enrollment_subject_id' => $this->secondEnrollmentSubject->id, 'score' => 12 + $index],
                ],
            ])->assertOk();
        }
    }

    public function test_export_contains_authorized_structure_logo_protection_and_stable_metadata(): void
    {
        $response = $this->withHeader('X-CDM-Client', 'web')->get('/api/grading/sheets/'.$this->sheetId.'/workbook?period=midterm');
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $this->temporaryWorkbook($response->streamedContent());
        $workbook = IOFactory::load($path);

        $this->assertSame(['Grade Entry', '_CDM_Metadata'], $workbook->getSheetNames());
        $entry = $workbook->getSheetByName('Grade Entry');
        $metadata = $workbook->getSheetByName('_CDM_Metadata');
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $metadata->getSheetState());
        $this->assertTrue($entry->getProtection()->getSheet());
        $this->assertFalse($entry->getColumnDimension('A')->getVisible());
        $this->assertSame('COLEGIO DE MONTALBAN', $entry->getCell('B1')->getValue());
        $this->assertSame('Quiz 1', $entry->getCell('E9')->getValue());
        $this->assertSame('Activity 1', $entry->getCell('F9')->getValue());
        $this->assertSame('Maximum 20', $entry->getCell('E10')->getValue());
        $this->assertNotEmpty($entry->getDrawingCollection());
        $this->assertSame(ProfessorGradesheetWorkbookService::TEMPLATE_VERSION, $metadata->getCell('B1')->getValue());
        $this->assertSame('decimal', $entry->getCell('E11')->getDataValidation()->getType());
        $this->assertSame('unprotected', $entry->getStyle('E11')->getProtection()->getLocked());
        $this->assertFalse($entry->getProtection()->getSelectUnlockedCells());
        $this->assertTrue($entry->getProtection()->getSelectLockedCells());
        $this->assertSame('E11', $entry->getSelectedCells());

        $workbook->disconnectWorksheets();
        @unlink($path);
    }

    public function test_serialized_midterm_workbook_unlocks_populated_blank_and_zero_score_cells_only(): void
    {
        GradeScore::query()
            ->where('grade_assessment_id', $this->assessments[0]['id'])
            ->where('enrollment_subject_id', $this->secondEnrollmentSubject->id)
            ->update(['score' => 0]);
        GradeScore::query()
            ->where('grade_assessment_id', $this->assessments[1]['id'])
            ->where('enrollment_subject_id', $this->secondEnrollmentSubject->id)
            ->delete();

        $path = $this->exportedWorkbookPath();
        $workbook = IOFactory::load($path);
        $entry = $workbook->getSheetByName('Grade Entry');
        $metadata = $workbook->getSheetByName('_CDM_Metadata');

        foreach (['E11', 'F11', 'E12', 'F12'] as $coordinate) {
            $this->assertSame(Protection::PROTECTION_UNPROTECTED, $entry->getStyle($coordinate)->getProtection()->getLocked(), $coordinate.' must be editable.');
            $this->assertSerializedCellLocked($path, $coordinate, false);
        }
        $secondStudentRow = collect(range(11, 12))->first(
            fn (int $row) => (int) $entry->getCell('A'.$row)->getValue() === $this->secondEnrollmentSubject->id
        );
        $this->assertNotNull($secondStudentRow);
        $this->assertSame(0.0, $entry->getCell('E'.$secondStudentRow)->getValue());
        $this->assertNull($entry->getCell('F'.$secondStudentRow)->getValue());

        foreach (['B1', 'C11', 'D11', 'E9', 'E10', 'G11', 'H11', 'L11'] as $coordinate) {
            $this->assertNotSame(Protection::PROTECTION_UNPROTECTED, $entry->getStyle($coordinate)->getProtection()->getLocked(), $coordinate.' must remain protected.');
            $this->assertSerializedCellLocked($path, $coordinate, true);
        }

        $this->assertTrue($metadata->getProtection()->getSheet());
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $metadata->getSheetState());
        $this->assertSerializedSelectionProtection($path);

        $entry->setCellValue('D11', 'Tampered Student Name');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $preview = $this->postPreview($path)->assertOk()->json('data');
        $this->assertTrue(collect($preview['issues'])->pluck('type')->contains('identity-changed'));
        @unlink($path);
    }

    public function test_serialized_finals_workbook_score_cells_are_editable(): void
    {
        $assessment = $this->withHeader('X-CDM-Client', 'web')
            ->postJson('/api/grading/sheets/'.$this->sheetId.'/assessments', [
                'period' => 'finals',
                'label' => 'Final Project',
                'category' => 'Major Exam',
                'max_score' => 100,
                'display_order' => 1,
            ])
            ->assertCreated()
            ->json('data');
        $this->withHeader('X-CDM-Client', 'web')->putJson('/api/grading/sheets/'.$this->sheetId.'/scores', [
            'assessment_id' => $assessment['id'],
            'scores' => [
                ['enrollment_subject_id' => $this->firstEnrollmentSubject->id, 'score' => 75],
            ],
        ])->assertOk();

        $path = $this->exportedWorkbookPath('finals');
        $workbook = IOFactory::load($path);
        $entry = $workbook->getSheetByName('Grade Entry');

        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $entry->getStyle('E11')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $entry->getStyle('E12')->getProtection()->getLocked());
        $this->assertSerializedCellLocked($path, 'E11', false);
        $this->assertSerializedCellLocked($path, 'E12', false);

        $workbook->disconnectWorksheets();
        @unlink($path);
    }

    public function test_preview_supports_sorted_rows_preserves_blank_and_distinguishes_zero_without_writes(): void
    {
        $path = $this->exportedWorkbookPath();
        $workbook = IOFactory::load($path);
        $entry = $workbook->getSheetByName('Grade Entry');
        $lastColumn = Coordinate::columnIndexFromString($entry->getHighestDataColumn());
        $first = $entry->rangeToArray('A11:'.Coordinate::stringFromColumnIndex($lastColumn).'11', null, true, false)[0];
        $second = $entry->rangeToArray('A12:'.Coordinate::stringFromColumnIndex($lastColumn).'12', null, true, false)[0];
        $entry->fromArray($second, null, 'A11');
        $entry->fromArray($first, null, 'A12');
        $entry->setCellValue('E11', 0);
        $entry->setCellValue('E12', null);
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        $before = GradeScore::query()->orderBy('id')->get()->map->only(['id', 'score', 'version'])->all();
        $preview = $this->postPreview($path)->assertOk()->json('data');
        $after = GradeScore::query()->orderBy('id')->get()->map->only(['id', 'score', 'version'])->all();

        $this->assertSame(2, $preview['summary']['recognized_students']);
        $this->assertSame(1, $preview['summary']['proposed_changes']);
        $this->assertSame(0, $preview['summary']['conflicts']);
        $this->assertSame(0, $preview['changes'][0]['imported']);
        $this->assertSame('ready', $preview['changes'][0]['status']);
        $this->assertTrue($preview['can_apply']);
        $this->assertNotEmpty($preview['proposal_token']);
        $this->assertSame($before, $after, 'Preview must not write or version any grade score.');
        @unlink($path);
    }

    public function test_valid_proposal_applies_positive_and_zero_scores_as_drafts_without_changing_blanks(): void
    {
        $this->score($this->assessments[1], $this->secondEnrollmentSubject)->delete();
        $before = GradeScore::query()->orderBy('id')->get()->map->only(['id', 'score', 'version'])->all();
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
            ['student' => $this->secondEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 0],
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[1], 'score' => null],
            ['student' => $this->secondEnrollmentSubject, 'assessment' => $this->assessments[1], 'score' => 45],
        ]);

        $this->assertTrue($preview['can_apply']);
        $this->assertSame(3, $preview['summary']['proposed_changes']);
        $this->assertSame($before, GradeScore::query()->orderBy('id')->get()->map->only(['id', 'score', 'version'])->all());
        $result = $this->applyProposal($preview['proposal_token'])->assertOk()->json('data');
        $this->assertSame(3, $result['applied_scores']);
        $this->assertSame(2, $result['affected_students']);
        $this->assertFalse($result['replayed']);
        $this->assertSame(18.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);
        $this->assertSame(0.0, $this->score($this->assessments[0], $this->secondEnrollmentSubject)->score);
        $this->assertSame(11.0, $this->score($this->assessments[1], $this->firstEnrollmentSubject)->score, 'Blank workbook cells must remain unchanged.');
        $this->assertSame(45.0, $this->score($this->assessments[1], $this->secondEnrollmentSubject)->score);
        $sheet = GradeSheet::findOrFail($this->sheetId);
        $this->assertSame('draft', $sheet->status);
        $this->assertNull($sheet->submitted_at);

        $audit = GradeAuditEvent::where('action', 'scores.excel_import_applied')->sole();
        $this->assertSame($this->fixture['professorUser']->id, $audit->actor_user_id);
        $this->assertSame(3, $audit->metadata['changed_score_count']);
        $this->assertSame($result['operation_id'], $audit->metadata['operation_id']);
        $this->assertSame('excel_import', $audit->metadata['source']);
        $this->assertArrayNotHasKey('workbook', $audit->metadata);
    }

    public function test_apply_rejects_foreign_professor_wrong_grade_sheet_and_non_web_client(): void
    {
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);

        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $profile = $otherUser->profile()->create(['first_name' => 'Unauthorized', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        Professor::create([
            'user_id' => $otherUser->id,
            'user_profile_id' => $profile->id,
            'department_id' => $this->fixture['department']->id,
            'employee_number' => 'FOREIGN-APPLY',
            'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser);
        $this->applyProposal($preview['proposal_token'])->assertNotFound();

        Sanctum::actingAs($this->fixture['professorUser']);
        $secondAssignment = SectionSubject::create([
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][1]->id,
            'professor_id' => $this->fixture['professor']->id,
        ]);
        $otherSheet = $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/classes/'.$secondAssignment->id.'/workspace')->assertOk()->json('data.sheet.id');
        $this->applyProposal($preview['proposal_token'], $otherSheet)->assertUnprocessable();
        $this->applyProposal($preview['proposal_token'], $this->sheetId, 'midterm', 'mobile')->assertForbidden();
        $this->assertSame(10.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);
    }

    public function test_apply_revalidates_invalid_scores_and_rolls_back_the_whole_import(): void
    {
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
            ['student' => $this->secondEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 19],
        ]);
        $key = $this->proposalCacheKey($preview['proposal_token']);
        $proposal = Cache::get($key);
        $proposal['changes'][1]['imported'] = 999;
        Cache::put($key, $proposal, now()->addMinutes(10));

        $this->applyProposal($preview['proposal_token'])->assertUnprocessable();
        $this->assertSame(10.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);
        $this->assertSame(12.0, $this->score($this->assessments[0], $this->secondEnrollmentSubject)->score);
        $this->assertDatabaseMissing('grade_audit_events', ['action' => 'scores.excel_import_applied']);
    }

    public function test_apply_rejects_changed_schedule_and_submitted_grade_sheet(): void
    {
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        GradePeriodSchedule::query()->update(['midterm_deadline' => now()->subMinute(), 'version' => 2]);
        $this->applyProposal($preview['proposal_token'])->assertConflict();
        $this->assertSame(10.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);

        GradePeriodSchedule::query()->update(['midterm_deadline' => now()->addHour(), 'version' => 3]);
        $submittedPreview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        GradeSheet::whereKey($this->sheetId)->update(['status' => 'submitted', 'submitted_at' => now(), 'version' => 2]);
        $this->applyProposal($submittedPreview['proposal_token'])->assertConflict();
        $this->assertSame(10.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);
    }

    public function test_apply_rejects_changed_assessment_roster_and_concurrent_score(): void
    {
        $assessmentPreview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        GradeAssessment::whereKey($this->assessments[0]['id'])->increment('version');
        $this->applyProposal($assessmentPreview['proposal_token'])->assertConflict();

        GradeAssessment::whereKey($this->assessments[0]['id'])->decrement('version');
        $rosterPreview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        $this->secondEnrollmentSubject->update(['subject_status' => 'dropped']);
        $this->applyProposal($rosterPreview['proposal_token'])->assertConflict();

        $this->secondEnrollmentSubject->update(['subject_status' => 'enrolled']);
        $scorePreview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        $score = $this->score($this->assessments[0], $this->firstEnrollmentSubject);
        $score->update(['score' => 17, 'version' => $score->version + 1]);
        $this->applyProposal($scorePreview['proposal_token'])->assertConflict();
        $this->assertSame(17.0, $score->fresh()->score);
    }

    public function test_database_failure_rolls_back_every_imported_score_and_audit(): void
    {
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
            ['student' => $this->secondEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 19],
        ]);
        DB::statement("CREATE TRIGGER fail_second_excel_score BEFORE UPDATE ON grade_scores WHEN NEW.score = 19 BEGIN SELECT RAISE(ABORT, 'forced import failure'); END");
        try {
            $this->applyProposal($preview['proposal_token'])->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_second_excel_score');
        }

        $this->assertSame(10.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);
        $this->assertSame(12.0, $this->score($this->assessments[0], $this->secondEnrollmentSubject)->score);
        $this->assertDatabaseMissing('grade_audit_events', ['action' => 'scores.excel_import_applied']);
    }

    public function test_completed_proposal_is_idempotent_and_does_not_duplicate_audit_events(): void
    {
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        $this->applyProposal($preview['proposal_token'], $this->sheetId, 'midterm', 'web', false)->assertUnprocessable();
        $first = $this->applyProposal($preview['proposal_token'])->assertOk()->json('data');
        $second = $this->applyProposal($preview['proposal_token'])->assertOk()->json('data');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame(1, GradeAuditEvent::where('action', 'scores.excel_import_applied')->count());
        $this->assertSame(2, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->version);
    }

    public function test_expired_and_tampered_proposals_are_rejected(): void
    {
        $preview = $this->previewWithChanges([
            ['student' => $this->firstEnrollmentSubject, 'assessment' => $this->assessments[0], 'score' => 18],
        ]);
        $token = $preview['proposal_token'];
        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->applyProposal($tampered)->assertUnprocessable();

        Cache::forget($this->proposalCacheKey($token));
        $this->applyProposal($token)->assertConflict();
        $this->assertSame(10.0, $this->score($this->assessments[0], $this->firstEnrollmentSubject)->score);
    }

    public function test_preview_rejects_formulas_duplicates_invalid_scores_and_tampered_metadata(): void
    {
        $path = $this->exportedWorkbookPath();
        $workbook = IOFactory::load($path);
        $entry = $workbook->getSheetByName('Grade Entry');
        $entry->setCellValue('A12', $entry->getCell('A11')->getValue());
        $entry->setCellValue('E11', '=1+1');
        $entry->setCellValue('F11', 999);
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        $preview = $this->postPreview($path)->assertOk()->json('data');
        $types = collect($preview['issues'])->pluck('type');
        $this->assertTrue($types->contains('formula'));
        $this->assertTrue($types->contains('duplicate-student'));
        $this->assertGreaterThan(0, $preview['summary']['invalid_cells']);
        $this->assertNotEmpty($preview['blocking_errors']);

        $tampered = $this->exportedWorkbookPath();
        $workbook = IOFactory::load($tampered);
        $metadata = $workbook->getSheetByName('_CDM_Metadata');
        $metadata->setCellValue('B4', $metadata->getCell('B4')->getValue().'tampered');
        (new Xlsx($workbook))->save($tampered);
        $workbook->disconnectWorksheets();
        $this->postPreview($tampered)->assertUnprocessable()->assertJsonPath('message', 'Workbook metadata was altered or is not an authentic CDM Portal export.');

        @unlink($path);
        @unlink($tampered);
    }

    public function test_preview_reports_concurrent_score_definition_and_period_lock_conflicts(): void
    {
        $path = $this->exportedWorkbookPath();
        $workbook = IOFactory::load($path);
        $entry = $workbook->getSheetByName('Grade Entry');
        for ($row = 11; $row <= $entry->getHighestDataRow(); $row++) {
            if ((int) $entry->getCell('A'.$row)->getValue() === $this->firstEnrollmentSubject->id) {
                $entry->setCellValue('E'.$row, 18);
                break;
            }
        }
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        $score = GradeScore::where('grade_assessment_id', $this->assessments[0]['id'])
            ->where('enrollment_subject_id', $this->firstEnrollmentSubject->id)
            ->firstOrFail();
        $score->update(['score' => 17, 'version' => $score->version + 1]);
        GradeAssessment::whereKey($this->assessments[1]['id'])->increment('version');
        GradePeriodSchedule::query()->update(['midterm_deadline' => now()->subMinute()]);

        $preview = $this->postPreview($path)->assertOk()->json('data');
        $this->assertSame(1, $preview['summary']['conflicts']);
        $this->assertSame('conflict', $preview['changes'][0]['status']);
        $this->assertTrue(collect($preview['blocking_errors'])->contains(fn ($message) => str_contains($message, 'Assessment definitions changed')));
        $this->assertTrue(collect($preview['blocking_errors'])->contains(fn ($message) => str_contains($message, 'grade entry is Closed')));
        @unlink($path);
    }

    public function test_foreign_professor_and_non_web_client_cannot_export_or_preview(): void
    {
        $path = $this->exportedWorkbookPath();
        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $profile = $otherUser->profile()->create(['first_name' => 'Foreign', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        Professor::create([
            'user_id' => $otherUser->id,
            'user_profile_id' => $profile->id,
            'department_id' => $this->fixture['department']->id,
            'employee_number' => 'FOREIGN-EXCEL',
            'status' => 'active',
        ]);

        Sanctum::actingAs($otherUser);
        $this->withHeader('X-CDM-Client', 'web')->get('/api/grading/sheets/'.$this->sheetId.'/workbook?period=midterm')->assertNotFound();
        $this->withHeader('X-CDM-Client', 'web')->post('/api/grading/sheets/'.$this->sheetId.'/workbook/preview', [
            'period' => 'midterm',
            'file' => $this->upload($path),
        ], ['Accept' => 'application/json'])->assertNotFound();

        Sanctum::actingAs($this->fixture['professorUser']);
        $this->withHeader('X-CDM-Client', 'mobile')->get('/api/grading/sheets/'.$this->sheetId.'/workbook?period=midterm')->assertForbidden();
        @unlink($path);
    }

    private function enroll(Student $student): EnrollmentSubject
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'section_id' => $this->fixture['section']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'enrolled',
        ]);

        return EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
            'subject_status' => 'enrolled',
        ]);
    }

    private function exportedWorkbookPath(string $period = 'midterm'): string
    {
        $export = app(ProfessorGradesheetWorkbookService::class)->export(
            $this->fixture['professorUser'],
            GradeSheet::findOrFail($this->sheetId),
            $period,
        );
        $path = tempnam(sys_get_temp_dir(), 'cdm-excel-');
        (new Xlsx($export['workbook']))->save($path);
        $export['workbook']->disconnectWorksheets();

        return $path;
    }

    private function temporaryWorkbook(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cdm-download-');
        file_put_contents($path, $content);

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'gradesheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function postPreview(string $path)
    {
        return $this->withHeader('X-CDM-Client', 'web')->post('/api/grading/sheets/'.$this->sheetId.'/workbook/preview', [
            'period' => 'midterm',
            'file' => $this->upload($path),
        ], ['Accept' => 'application/json']);
    }

    /** @param array<int, array{student: EnrollmentSubject, assessment: array, score: int|float|null}> $changes */
    private function previewWithChanges(array $changes): array
    {
        $path = $this->exportedWorkbookPath();
        $workbook = IOFactory::load($path);
        $entry = $workbook->getSheetByName('Grade Entry');
        foreach ($changes as $change) {
            $row = collect(range(11, $entry->getHighestDataRow()))->first(
                fn (int $row) => (int) $entry->getCell('A'.$row)->getValue() === $change['student']->id
            );
            $assessmentIndex = collect($this->assessments)->search(fn (array $assessment) => (int) $assessment['id'] === (int) $change['assessment']['id']);
            $entry->setCellValue(Coordinate::stringFromColumnIndex(5 + $assessmentIndex).$row, $change['score']);
        }
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $preview = $this->postPreview($path)->assertOk()->json('data');
        @unlink($path);

        return $preview;
    }

    private function applyProposal(string $token, ?int $sheetId = null, string $period = 'midterm', string $client = 'web', bool $confirmed = true)
    {
        return $this->withHeader('X-CDM-Client', $client)->postJson('/api/grading/sheets/'.($sheetId ?? $this->sheetId).'/workbook/apply', [
            'period' => $period,
            'proposal_token' => $token,
            'confirmed' => $confirmed,
        ]);
    }

    private function score(array $assessment, EnrollmentSubject $student): GradeScore
    {
        return GradeScore::where('grade_assessment_id', $assessment['id'])->where('enrollment_subject_id', $student->id)->firstOrFail();
    }

    private function proposalCacheKey(string $token): string
    {
        return 'professor-gradesheet-import:proposal:'.hash('sha256', $token);
    }

    private function assertSerializedCellLocked(string $path, string $coordinate, bool $expectedLocked): void
    {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path) === true);
        $worksheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $stylesXml = $archive->getFromName('xl/styles.xml');
        $archive->close();

        $this->assertIsString($worksheetXml);
        $this->assertIsString($stylesXml);
        preg_match('/<c[^>]*\br="'.preg_quote($coordinate, '/').'"[^>]*\bs="(\d+)"/', $worksheetXml, $cellMatch);
        $this->assertArrayHasKey(1, $cellMatch, 'Serialized cell '.$coordinate.' must reference an explicit style.');

        $styles = new \DOMDocument;
        $styles->loadXML($stylesXml);
        $xpath = new \DOMXPath($styles);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xf = $xpath->query('/m:styleSheet/m:cellXfs/m:xf')->item((int) $cellMatch[1]);
        $this->assertNotNull($xf);
        $protection = $xpath->query('./m:protection', $xf)->item(0);
        $lockedValue = strtolower($protection?->attributes->getNamedItem('locked')?->nodeValue ?? 'true');
        $serializedLocked = ! in_array($lockedValue, ['0', 'false'], true);

        $this->assertSame($expectedLocked, $serializedLocked, 'Serialized style protection mismatch for '.$coordinate.'.');
    }

    private function assertSerializedSelectionProtection(string $path): void
    {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path) === true);
        $worksheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();

        $this->assertIsString($worksheetXml);
        preg_match('/<sheetProtection\b[^>]*>/', $worksheetXml, $match);
        $this->assertArrayHasKey(0, $match);
        $this->assertStringContainsString('selectUnlockedCells="0"', $match[0]);
        $this->assertStringContainsString('selectLockedCells="1"', $match[0]);
    }
}

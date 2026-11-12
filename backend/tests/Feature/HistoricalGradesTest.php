<?php

namespace Tests\Feature;

use App\Models\HistoricalGradeEvent;
use App\Models\HistoricalGradeRecord;
use App\Models\Subject;
use App\Services\Enrollment\EnrollmentSubjectPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class HistoricalGradesTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->f = EnrollmentAcademicFixture::create('history');
        Sanctum::actingAs($this->f['registrar']);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'academic_year' => $this->f['year']->school_year,
            'semester' => $this->f['semester']->semester_name,
            'subject_code' => $this->f['subjects'][0]->subject_code,
            'final_grade' => '1.75', 'official_result' => 'Passed',
            'credited_units' => '3', 'source_reference' => 'Registrar archive volume 4, page 12',
        ], $changes);
    }

    private function url(): string
    {
        return '/api/registrar/students/'.$this->f['student']->id.'/historical-grades';
    }

    private function workbook(array $rows, ?array $headings = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headings ?? ['Student Number', 'Academic Year', 'Semester', 'Subject Code', 'Final Grade', 'Official Result', 'Credited Units', 'Source Reference'], null, 'A1');
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $cell = $sheet->getCell(chr(65 + $column).($index + 2));
                $cell->setValueExplicit($value, is_string($value) && str_starts_with($value, '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'historical-grade-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'historical-grades.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function row(array $changes = []): array
    {
        $data = $this->payload($changes);

        return [$this->f['student']->student_number, ...array_values($data)];
    }

    public function test_manual_entry_is_verified_and_visible_without_creating_a_published_grade(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated()->assertJsonPath('data.source_type', 'manual');
        $this->assertDatabaseCount('historical_grade_records', 1);
        $this->assertDatabaseCount('grade_sheets', 0);
        $this->assertDatabaseCount('enrollment_subjects', 0);
        $this->assertSame('created', HistoricalGradeEvent::firstOrFail()->action);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.official_result', 'Passed');
        Sanctum::actingAs($this->f['user']);
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonPath('data.historical_grades.0.final_grade', '1.75');
        $this->postJson($this->url(), $this->payload())->assertForbidden();
    }

    public function test_passed_history_counts_for_prerequisites_but_failed_disputed_and_revoked_do_not(): void
    {
        $dependent = $this->f['subjects'][1];
        $dependent->update(['prerequisite_subject_id' => $this->f['subjects'][0]->id]);
        $policy = app(EnrollmentSubjectPolicy::class);
        $this->assertFalse($policy->proposal($this->f['application'])['candidates'][1]['prerequisite_met']);
        $this->postJson($this->url(), $this->payload(['official_result' => 'Failed']))->assertCreated();
        $this->assertFalse($policy->proposal($this->f['application'])['candidates'][1]['prerequisite_met']);
        $record = HistoricalGradeRecord::firstOrFail();
        $this->patchJson($this->url().'/'.$record->id, $this->payload(['official_result' => 'Passed']) + ['reason' => 'Official transcript correction'])->assertOk();
        $this->assertTrue($policy->proposal($this->f['application'])['candidates'][1]['prerequisite_met']);
        $this->patchJson($this->url().'/'.$record->id, $this->payload() + ['reason' => 'Source document disputed', 'verification_status' => 'disputed'])->assertOk();
        $this->assertFalse($policy->proposal($this->f['application'])['candidates'][1]['prerequisite_met']);
        Sanctum::actingAs($this->f['user']);
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.historical_grades');
        $this->assertSame(3, HistoricalGradeEvent::count());
    }

    public function test_import_preview_confirmation_and_replay_are_atomic(): void
    {
        $file = $this->workbook([$this->row(), $this->row(['subject_code' => 'AC2', 'official_result' => 'Incomplete'])]);
        $preview = $this->post('/api/registrar/historical-grades/preview', ['file' => $file])->assertOk()
            ->assertJsonPath('data.counts.valid', 2)->json('data');
        $this->assertDatabaseCount('historical_grade_records', 0);
        $this->postJson('/api/registrar/historical-grades/confirm', ['preview_token' => $preview['preview_token']])
            ->assertOk()->assertJsonPath('data.imported', 2);
        $this->postJson('/api/registrar/historical-grades/confirm', ['preview_token' => $preview['preview_token']])->assertStatus(409);
        $this->assertDatabaseCount('historical_grade_records', 2);
        $this->assertSame(2, HistoricalGradeRecord::where('source_type', 'import')->count());
    }

    public function test_invalid_duplicate_unknown_and_changed_rows_save_nothing(): void
    {
        $preview = $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([
            $this->row(), $this->row(), $this->row(['subject_code' => 'MISSING']),
        ])])->assertOk()->json('data');
        $this->assertSame(1, $preview['counts']['duplicates']);
        $this->assertSame(1, $preview['counts']['unknown_subjects']);
        $this->assertNull($preview['preview_token']);
        $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([$this->row(['subject_code' => 'AC2'])])])->assertOk();
        $this->assertDatabaseCount('historical_grade_records', 0);

        $preview = $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([$this->row()])])->assertOk()->json('data');
        Subject::whereKey($this->f['subjects'][0]->id)->update(['subject_code' => 'CHANGED']);
        $this->postJson('/api/registrar/historical-grades/confirm', ['preview_token' => $preview['preview_token']])->assertUnprocessable();
        $this->assertDatabaseCount('historical_grade_records', 0);
    }

    public function test_malformed_formula_missing_columns_and_bad_manual_inputs_fail_closed(): void
    {
        $this->postJson('/api/registrar/historical-grades/preview', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
        $this->post('/api/registrar/historical-grades/preview', ['file' => UploadedFile::fake()->create('grades.xlsx', 2)])->assertUnprocessable();
        $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([$this->row()], ['Wrong heading'])])->assertUnprocessable();
        $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([$this->row(['final_grade' => '=SUM(A1:A2)'])])])->assertUnprocessable();
        foreach ([['source_reference' => ''], ['official_result' => 'Unknown'], ['academic_year' => '1900-1901'], ['subject_code' => 'UNKNOWN']] as $change) {
            $this->postJson($this->url(), $this->payload($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('historical_grade_records', 0);
    }

    public function test_corrections_require_reason_and_preserve_previous_values(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $record = HistoricalGradeRecord::firstOrFail();
        $this->patchJson($this->url().'/'.$record->id, $this->payload(['final_grade' => '2.00']))->assertUnprocessable();
        $this->patchJson($this->url().'/'.$record->id, $this->payload(['final_grade' => '2.00']) + ['reason' => 'Corrected from signed transcript'])->assertOk();
        $event = HistoricalGradeEvent::where('action', 'corrected')->firstOrFail();
        $this->assertSame('1.75', $event->before['final_grade']);
        $this->assertSame('2.00', $event->after['final_grade']);
        $this->assertSame($this->f['registrar']->id, $event->actor_user_id);
    }

    public function test_template_is_a_readable_workbook_with_required_columns(): void
    {
        $response = $this->get('/api/registrar/historical-grades/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'historical-template-');
        file_put_contents($path, $response->streamedContent());
        try {
            $book = IOFactory::load($path);
            $this->assertSame('Student Number', $book->getSheet(0)->getCell('A1')->getValue());
            $this->assertSame('Source Reference', $book->getSheet(0)->getCell('H1')->getValue());
            $this->assertSame('Instructions', $book->getSheet(1)->getTitle());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_preview_token_is_bound_to_staff_and_correction_cannot_cross_students(): void
    {
        $preview = $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([$this->row()])])->assertOk()->json('data');
        Sanctum::actingAs($this->f['user']);
        $this->postJson('/api/registrar/historical-grades/confirm', ['preview_token' => $preview['preview_token']])->assertForbidden();
        Sanctum::actingAs($this->f['registrar']);
        $this->postJson('/api/registrar/historical-grades/confirm', ['preview_token' => $preview['preview_token']])->assertOk();
        $record = HistoricalGradeRecord::firstOrFail();
        $this->patchJson('/api/registrar/students/999999/historical-grades/'.$record->id, $this->payload() + ['reason' => 'Wrong owner attempt'])->assertNotFound();
        $this->assertSame($this->f['student']->id, $record->fresh()->student_id);
    }

    public function test_unknown_student_and_unsupported_result_block_the_entire_import(): void
    {
        $unknown = $this->row();
        $unknown[0] = 'UNKNOWN-STUDENT';
        $preview = $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([
            $this->row(), $unknown, $this->row(['subject_code' => 'AC2', 'official_result' => 'Withdrawn']),
        ])])->assertOk()->json('data');
        $this->assertSame(1, $preview['counts']['unknown_students']);
        $this->assertSame(2, $preview['counts']['invalid']);
        $this->assertNull($preview['preview_token']);
        $this->assertDatabaseCount('historical_grade_records', 0);
    }

    public function test_confirmation_rolls_back_every_record_when_a_later_insert_fails(): void
    {
        $preview = $this->post('/api/registrar/historical-grades/preview', ['file' => $this->workbook([
            $this->row(), $this->row(['subject_code' => 'AC2']),
        ])])->assertOk()->json('data');
        DB::statement("CREATE TRIGGER reject_second_historical_grade BEFORE INSERT ON historical_grade_records WHEN NEW.subject_code = 'AC2' BEGIN SELECT RAISE(ABORT, 'simulated insert failure'); END");
        try {
            $this->withoutExceptionHandling();
            try {
                $this->postJson('/api/registrar/historical-grades/confirm', ['preview_token' => $preview['preview_token']]);
                $this->fail('The simulated database failure should abort confirmation.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('simulated insert failure', $exception->getMessage());
            }
        } finally {
            DB::statement('DROP TRIGGER reject_second_historical_grade');
        }
        $this->assertDatabaseCount('historical_grade_records', 0);
        $this->assertDatabaseCount('historical_grade_events', 0);
    }

    public function test_migration_rollback_refuses_to_erase_official_history(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $migration = require database_path('migrations/2026_11_13_000001_create_historical_grade_records.php');

        try {
            $migration->down();
            $this->fail('The migration must not erase official historical grades.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('data-preserving rollback plan', $exception->getMessage());
        }

        $this->assertDatabaseCount('historical_grade_records', 1);
        $this->assertDatabaseCount('historical_grade_events', 1);
    }
}

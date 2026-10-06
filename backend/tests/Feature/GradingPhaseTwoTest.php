<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeAuditEvent;
use App\Models\GradePeriodSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use App\Services\Grading\GradingWorkflowService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class GradingPhaseTwoTest extends TestCase
{
    private array $f;

    private GradeSheet $sheet;

    private EnrollmentSubject $enrollmentSubject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->f = EnrollmentAcademicFixture::create('grading-two');
        $assignment = SectionSubject::create(['section_id' => $this->f['section']->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id]);
        $enrollment = Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
        $this->enrollmentSubject = EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id, 'subject_status' => 'enrolled']);
        Sanctum::actingAs($this->f['professorUser']);
        $this->postJson('/api/grading/classes/'.$assignment->id.'/workspace')->assertOk();
        $this->sheet = GradeSheet::where('section_subject_id', $assignment->id)->firstOrFail();
        GradePeriodSchedule::create(['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'midterm_opens_at' => now()->subDays(10), 'midterm_deadline' => now()->subDays(6), 'finals_opens_at' => now()->subDays(5), 'finals_deadline' => now()->subDay(), 'created_by' => $this->f['registrar']->id, 'updated_by' => $this->f['registrar']->id]);
        $this->completeSheet();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function completeSheet(): void
    {
        $scores = ['midterm' => ['Quiz' => 80, 'Activity' => 90, 'Recitation' => 100, 'Major Exam' => 70], 'finals' => ['Quiz' => 100, 'Activity' => 80, 'Recitation' => 90, 'Major Exam' => 95]];
        $order = 1;
        foreach ($scores as $period => $categories) {
            foreach ($categories as $category => $score) {
                $assessment = GradeAssessment::create(['grade_sheet_id' => $this->sheet->id, 'period' => $period, 'label' => $period.' '.$category, 'category' => $category, 'max_score' => 100, 'display_order' => $order++]);
                GradeScore::create(['grade_assessment_id' => $assessment->id, 'enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => $score, 'updated_by' => $this->f['professorUser']->id]);
            }
        }
    }

    private function submit(): array
    {
        Sanctum::actingAs($this->f['professorUser']);

        return $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->fresh()->version])->assertOk()->json('data');
    }

    private function registrarStepUp(): void
    {
        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
    }

    private function approve(): array
    {
        $submitted = $this->submit();
        $this->registrarStepUp();

        return $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertOk()->json('data');
    }

    public function test_server_computation_applies_category_and_final_weights_with_deterministic_rounding(): void
    {
        GradeAssessment::create(['grade_sheet_id' => $this->sheet->id, 'period' => 'midterm', 'label' => 'Archived draft', 'category' => 'Quiz', 'max_score' => 100, 'display_order' => 99, 'status' => 'archived']);
        $computed = app(GradingWorkflowService::class)->compute($this->sheet);
        $this->assertTrue($computed['ready']);
        $this->assertSame(81.5, $computed['results'][0]['midterm_grade']);
        $this->assertSame(90, $computed['results'][0]['finals_grade']);
        $this->assertSame(86.6, $computed['results'][0]['final_grade']);
        $this->assertNull($computed['results'][0]['grade_point']);
        $this->assertNull($computed['results'][0]['remarks']);
        $this->assertSame('integer hundredths, half up', $computed['configuration']['rounding']);
        $this->assertCount(8, $computed['configuration']['assessments']);
    }

    public function test_missing_scores_invalid_weights_and_missing_assessments_block_submission(): void
    {
        GradeScore::query()->first()->delete();
        Sanctum::actingAs($this->f['professorUser']);
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('grade_submission_attempts', 0);
        $score = GradeScore::create(['grade_assessment_id' => GradeAssessment::query()->first()->id, 'enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 80, 'updated_by' => $this->f['professorUser']->id]);
        $this->sheet->weights()->where('period', 'midterm')->where('category', 'Quiz')->update(['weight_percentage' => 14]);
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => 1])->assertUnprocessable();
        $this->sheet->weights()->where('period', 'midterm')->where('category', 'Quiz')->update(['weight_percentage' => 15]);
        $score->delete();
        GradeAssessment::query()->first()->delete();
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => 1])->assertUnprocessable();
    }

    public function test_submission_snapshots_computed_results_and_locks_professor_edits(): void
    {
        $submitted = $this->submit();
        $this->assertSame('submitted', $submitted['status']);
        $this->assertDatabaseHas('grade_submission_attempts', ['grade_sheet_id' => $this->sheet->id, 'attempt_number' => 1]);
        $this->assertDatabaseHas('grade_submission_students', ['student_number' => $this->f['student']->student_number, 'final_grade' => 86.60]);
        $this->assertNotEmpty($submitted['latest_submission']['checksum']);
        Sanctum::actingAs($this->f['professorUser']);
        $assessment = GradeAssessment::query()->first();
        $score = GradeScore::where('grade_assessment_id', $assessment->id)->first();
        $this->putJson('/api/grading/sheets/'.$this->sheet->id.'/scores', ['assessment_id' => $assessment->id, 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 75, 'version' => $score->version]]])->assertConflict();
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/assessments', ['period' => 'midterm', 'label' => 'Extra', 'category' => 'Quiz', 'max_score' => 20, 'display_order' => 20])->assertConflict();
        $this->putJson('/api/grading/sheets/'.$this->sheet->id.'/final-weights', ['midterm_weight' => 50, 'finals_weight' => 50, 'version' => $submitted['version']])->assertConflict();
        $weights = $this->sheet->weights()->where('period', 'midterm')->get()->map(fn ($weight) => ['category' => $weight->category, 'weight_percentage' => $weight->weight_percentage, 'version' => $weight->version])->all();
        $this->putJson('/api/grading/sheets/'.$this->sheet->id.'/weights/midterm', ['weights' => $weights])->assertConflict();
    }

    public function test_return_requires_reason_unlocks_edits_and_resubmission_preserves_history(): void
    {
        $submitted = $this->submit();
        $firstAttempt = $this->sheet->submissions()->firstOrFail();
        $firstChecksum = $firstAttempt->checksum;
        $this->registrarStepUp();
        $url = '/api/grading/reviews/'.$this->sheet->id.'/return';
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($url, ['version' => $submitted['version']])->assertUnprocessable();
        $returned = $this->withHeader('X-CDM-Client', 'desktop')->postJson($url, ['version' => $submitted['version'], 'reason' => 'Please verify the activity score.'])->assertOk()->json('data');
        $this->assertSame('returned', $returned['status']);
        $this->assertSame('Please verify the activity score.', $returned['return_reason']);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_sheet.returned']);
        Sanctum::actingAs($this->f['professorUser']);
        $this->withHeader('X-CDM-Client', 'web');
        $assessment = GradeAssessment::where('period', 'midterm')->first();
        $score = GradeScore::where('grade_assessment_id', $assessment->id)->first();
        $this->putJson('/api/grading/sheets/'.$this->sheet->id.'/scores', ['assessment_id' => $assessment->id, 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => $score->score, 'version' => $score->version]]])->assertOk();
        $resubmitted = $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $returned['version']])->assertOk()->json('data');
        $this->assertSame('submitted', $resubmitted['status']);
        $this->assertDatabaseCount('grade_submission_attempts', 2);
        $this->assertSame($firstChecksum, $firstAttempt->fresh()->checksum);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_sheet.resubmitted']);
    }

    public function test_review_access_approval_rules_step_up_and_stale_version_are_enforced(): void
    {
        $submitted = $this->submit();
        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/reviews')->assertOk()->assertJsonPath('data.summary.pending_review', 1);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/reviews/'.$this->sheet->id)->assertOk()->assertJsonPath('data.latest_submission.students.0.final_grade', 86.6);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertStatus(428);
        $this->registrarStepUp();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => 1])->assertConflict();
        $approved = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $this->assertSame($this->f['registrar']->id, $approved['reviewed_by']);
        $this->assertNotNull($approved['reviewed_at']);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_sheet.approved']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/return', ['version' => $approved['version'], 'reason' => 'Too late'])->assertConflict();
    }

    public function test_foreign_professor_student_guest_and_registrar_web_are_blocked(): void
    {
        $other = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        Sanctum::actingAs($other);
        $this->getJson('/api/grading/sheets/'.$this->sheet->id.'/readiness')->assertNotFound();
        Sanctum::actingAs($this->f['user']);
        $this->getJson('/api/grading/reviews')->assertForbidden();
        $guest = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::GUEST])->id]);
        Sanctum::actingAs($guest);
        $this->getJson('/api/grading/reviews')->assertForbidden();
        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/reviews')->assertForbidden();
        $submitted = $this->submit();
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/reviews')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertStatus(428);
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertOk();
    }

    public function test_approved_sheet_can_be_scheduled_and_future_release_is_not_automatic_early(): void
    {
        $approved = $this->approve();
        $payload = ['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'release_at' => now()->addHour()->toIso8601String(), 'grade_sheet_ids' => [$this->sheet->id]];
        $release = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases', $payload)->assertCreated()->json('data');
        $this->artisan('grading:release-due')->assertSuccessful();
        $this->assertSame('approved', $this->sheet->fresh()->status);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases/'.$release['id'].'/execute')->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertSame('published', $this->sheet->fresh()->status);
        $this->assertNotNull($this->sheet->fresh()->published_at);
        $this->assertSame($approved['version'] + 1, $this->sheet->fresh()->version);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_release.executed']);
    }

    public function test_due_release_is_transactional_idempotent_and_submitted_sheet_cannot_be_scheduled(): void
    {
        $submitted = $this->submit();
        $this->registrarStepUp();
        $payload = ['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'release_at' => now()->subMinute()->toIso8601String(), 'grade_sheet_ids' => [$this->sheet->id]];
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases', $payload)->assertConflict();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertOk();
        $release = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases', $payload)->assertCreated()->json('data');
        $this->artisan('grading:release-due')->assertSuccessful();
        $version = $this->sheet->fresh()->version;
        $this->artisan('grading:release-due')->assertSuccessful();
        $this->assertSame('published', $this->sheet->fresh()->status);
        $this->assertSame($version, $this->sheet->fresh()->version);
        $this->assertDatabaseHas('grade_release_schedules', ['id' => $release['id'], 'status' => 'released']);
        $this->assertSame(1, GradeAuditEvent::where('action', 'grade_sheet.published')->count());
    }
}

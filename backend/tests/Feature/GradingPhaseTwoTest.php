<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeAuditEvent;
use App\Models\GradeLateSubmissionExtension;
use App\Models\GradePeriodSchedule;
use App\Models\GradeReleaseSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\GradingScale;
use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSubjectPolicy;
use App\Services\Grading\GradingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class GradingPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private GradeSheet $sheet;

    private EnrollmentSubject $enrollmentSubject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = EnrollmentAcademicFixture::create('grading-two');
        $assignment = SectionSubject::create(['section_id' => $this->f['section']->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id]);
        $enrollment = Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
        $this->enrollmentSubject = EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id, 'subject_status' => 'enrolled']);
        Sanctum::actingAs($this->f['professorUser']);
        $this->postJson('/api/grading/classes/'.$assignment->id.'/workspace')->assertOk();
        $this->sheet = GradeSheet::where('section_subject_id', $assignment->id)->firstOrFail();
        GradePeriodSchedule::create(['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'midterm_opens_at' => now()->subDays(10), 'midterm_deadline' => now()->subDays(6), 'finals_opens_at' => now()->subDay(), 'finals_deadline' => now()->addDays(5), 'created_by' => $this->f['registrar']->id, 'updated_by' => $this->f['registrar']->id]);
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

    private function configureOfficialOutcomes(): void
    {
        GradingScale::create(['min_percentage' => 0, 'max_percentage' => 74.99, 'grade_point' => 5, 'remark' => 'FAILED', 'is_active' => true]);
        GradingScale::create(['min_percentage' => 75, 'max_percentage' => 100, 'grade_point' => 1, 'remark' => 'PASSED', 'is_active' => true]);
    }

    private function releaseApprovedSheet(): int
    {
        $payload = ['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'release_at' => now()->subMinute()->toIso8601String(), 'grade_sheet_ids' => [$this->sheet->id]];
        $release = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases', $payload)->assertCreated()->json('data');
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases/'.$release['id'].'/execute')->assertOk();

        return $release['id'];
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

    public function test_grading_scale_populates_draft_computation_and_submitted_snapshot_stays_immutable(): void
    {
        GradingScale::create(['min_percentage' => 85, 'max_percentage' => 89.99, 'grade_point' => 2, 'remark' => 'Configured result', 'is_active' => true]);
        $computed = app(GradingWorkflowService::class)->compute($this->sheet);
        $this->assertTrue($computed['configuration']['grade_scale_configured']);
        $this->assertSame(2.0, $computed['results'][0]['grade_point']);
        $this->assertSame('Configured result', $computed['results'][0]['remarks']);

        $this->submit();
        $snapshot = $this->sheet->submissions()->firstOrFail()->students()->firstOrFail();
        $this->sheet->update(['status' => 'published', 'published_at' => now()]);
        GradingScale::query()->update(['grade_point' => 3, 'remark' => 'Later configuration']);

        $this->assertSame(2.0, $snapshot->fresh()->grade_point);
        $this->assertSame('Configured result', $snapshot->fresh()->remarks);
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

    public function test_submission_is_blocked_before_the_finals_submission_window_opens(): void
    {
        GradePeriodSchedule::query()->update([
            'finals_opens_at' => now()->addDay(),
            'finals_deadline' => now()->addDays(5),
        ]);
        Sanctum::actingAs($this->f['professorUser']);

        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->version])
            ->assertConflict()
            ->assertJsonPath('message', 'Grade submission is not open yet.');
        $this->assertDatabaseCount('grade_submission_attempts', 0);
    }

    public function test_submission_is_blocked_after_the_finals_submission_window_closes(): void
    {
        GradePeriodSchedule::query()->update([
            'finals_opens_at' => now()->subDays(5),
            'finals_deadline' => now()->subSecond(),
        ]);
        Sanctum::actingAs($this->f['professorUser']);

        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->version])
            ->assertConflict()
            ->assertJsonPath('message', 'Grade submission period has closed.');
        $this->assertDatabaseCount('grade_submission_attempts', 0);
    }

    public function test_targeted_late_access_unlocks_one_sheet_and_is_consumed_by_submission(): void
    {
        $period = GradePeriodSchedule::query()->firstOrFail();
        $period->update(['status' => 'archived', 'finals_deadline' => now()->subSecond()]);
        $assessment = GradeAssessment::where('period', 'finals')->firstOrFail();
        $score = GradeScore::where('grade_assessment_id', $assessment->id)->firstOrFail();

        Sanctum::actingAs($this->f['professorUser']);
        $this->putJson('/api/grading/sheets/'.$this->sheet->id.'/scores', [
            'assessment_id' => $assessment->id,
            'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => $score->score, 'version' => $score->version]],
        ])->assertConflict()->assertJsonPath('message', 'No active grading schedule is configured for this term.');
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->version])
            ->assertConflict()->assertJsonPath('message', 'Grade submission is not open yet.');

        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/periods/'.$period->id.'/late-extensions')
            ->assertOk()
            ->assertJsonPath('data.sheets.0.id', $this->sheet->id)
            ->assertJsonPath('data.sheets.0.eligible', true);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/periods/'.$period->id.'/late-extensions/'.$this->sheet->id, [
            'reason' => 'Professor submitted the verified grades after the institutional deadline.',
            'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(428);
        $this->registrarStepUp();
        $extension = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/periods/'.$period->id.'/late-extensions/'.$this->sheet->id, [
            'reason' => 'Professor submitted the verified grades after the institutional deadline.',
            'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.is_active', true)->json('data');
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_late_extension.granted', 'grade_sheet_id' => $this->sheet->id]);

        Sanctum::actingAs($this->f['professorUser']);
        $this->withHeader('X-CDM-Client', 'web');
        $this->postJson('/api/grading/classes/'.$this->sheet->sectionSubject->id.'/workspace')
            ->assertOk()
            ->assertJsonPath('data.late_submission_extension.id', $extension['id'])
            ->assertJsonPath('data.late_submission_extension.is_active', true);
        $this->putJson('/api/grading/sheets/'.$this->sheet->id.'/scores', [
            'assessment_id' => $assessment->id,
            'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => $score->score, 'version' => $score->version]],
        ])->assertOk();
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->fresh()->version])->assertOk();

        $this->assertDatabaseHas('grade_late_submission_extensions', ['id' => $extension['id'], 'status' => 'used']);
        $this->assertNotNull(GradeLateSubmissionExtension::findOrFail($extension['id'])->used_at);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_late_extension.used', 'grade_sheet_id' => $this->sheet->id]);
    }

    public function test_late_access_requires_future_expiry_and_can_be_revoked(): void
    {
        $period = GradePeriodSchedule::query()->firstOrFail();
        $period->update(['status' => 'archived', 'finals_deadline' => now()->subSecond()]);
        $this->registrarStepUp();
        $url = '/api/grading/periods/'.$period->id.'/late-extensions/'.$this->sheet->id;

        $this->withHeader('X-CDM-Client', 'desktop')->postJson($url, ['reason' => 'Late', 'expires_at' => now()->subMinute()->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'The reason must contain at least 5 characters.')
            ->assertJsonPath('errors.expires_at.0', 'The expiration must be in the future.');
        $extension = $this->withHeader('X-CDM-Client', 'desktop')->postJson($url, [
            'reason' => 'Documented late submission authorization.',
            'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->json('data');
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($url, [
            'reason' => 'Duplicate authorization must not be allowed.',
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ])->assertConflict();
        $this->withHeader('X-CDM-Client', 'desktop')->patchJson('/api/grading/late-extensions/'.$extension['id'].'/revoke', ['version' => 99])->assertConflict();
        $this->withHeader('X-CDM-Client', 'desktop')->patchJson('/api/grading/late-extensions/'.$extension['id'].'/revoke', ['version' => $extension['version']])
            ->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_late_extension.revoked', 'grade_sheet_id' => $this->sheet->id]);

        Sanctum::actingAs($this->f['professorUser']);
        $this->withHeader('X-CDM-Client', 'web');
        $this->postJson('/api/grading/classes/'.$this->sheet->sectionSubject->id.'/workspace')
            ->assertOk()->assertJsonPath('data.late_submission_extension', null);
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->fresh()->version])
            ->assertConflict()->assertJsonPath('message', 'Grade submission is not open yet.');
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
        $returned = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/return', [
            'version' => $approved['version'],
            'reason' => 'Correct the approved grading criteria before publication.',
        ])->assertOk()->json('data');
        $this->assertSame('returned', $returned['status']);
        $this->assertDatabaseHas('grade_audit_events', [
            'action' => 'grade_sheet.approved_returned_for_correction',
            'grade_sheet_id' => $this->sheet->id,
        ]);
    }

    public function test_foreign_professor_student_guest_and_registrar_web_are_blocked(): void
    {
        $other = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $profile = $other->profile()->create(['first_name' => 'Foreign', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        Professor::query()->create([
            'user_id' => $other->id,
            'user_profile_id' => $profile->id,
            'department_id' => $this->f['department']->id,
            'employee_number' => 'GRADE-TWO-FOREIGN',
            'status' => 'active',
        ]);
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
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/reviews')->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertStatus(428);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $submitted['version']])->assertOk();
    }

    public function test_approved_sheet_in_scheduled_release_cannot_be_returned_for_correction(): void
    {
        $approved = $this->approve();
        $payload = [
            'academic_year_id' => $this->f['year']->id,
            'semester_id' => $this->f['semester']->id,
            'release_at' => now()->addDay()->toIso8601String(),
            'grade_sheet_ids' => [$this->sheet->id],
        ];
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases', $payload)->assertCreated();

        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/return', [
            'version' => $approved['version'],
            'reason' => 'Attempt to change a sheet already queued for release.',
        ])->assertConflict()->assertJsonPath('message', 'This approved grade sheet is already in a scheduled release. Remove it from that release before returning it for correction.');
        $this->assertSame('approved', $this->sheet->fresh()->status);
    }

    public function test_approved_sheet_can_be_scheduled_and_future_release_is_not_automatic_early(): void
    {
        $this->configureOfficialOutcomes();
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
        $this->configureOfficialOutcomes();
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

    public function test_published_passing_results_complete_only_the_mapped_enrollment_subjects_and_enable_prerequisites(): void
    {
        $this->configureOfficialOutcomes();
        $unrelated = EnrollmentSubject::create([
            'enrollment_id' => $this->enrollmentSubject->enrollment_id,
            'subject_id' => $this->f['subjects'][1]->id,
            'subject_status' => 'enrolled',
            'remarks' => 'In Progress',
        ]);

        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $otherProfile = $otherUser->profile()->create(['first_name' => 'Second', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $otherStudent = Student::create([
            'user_id' => $otherUser->id,
            'user_profile_id' => $otherProfile->id,
            'course_id' => $this->f['course']->id,
            'curriculum_id' => $this->f['curriculum']->id,
            'student_number' => 'GRADE-TWO-SECOND',
            'admission_date' => today(),
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'section_id' => $this->f['section']->id,
            'academic_year_id' => $this->f['year']->id,
            'semester_id' => $this->f['semester']->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'enrolled',
        ]);
        $otherEnrollmentSubject = EnrollmentSubject::create([
            'enrollment_id' => $otherEnrollment->id,
            'subject_id' => $this->f['subjects'][0]->id,
            'professor_id' => $this->f['professor']->id,
            'subject_status' => 'enrolled',
            'remarks' => 'In Progress',
        ]);
        foreach (GradeAssessment::all() as $assessment) {
            GradeScore::create([
                'grade_assessment_id' => $assessment->id,
                'enrollment_subject_id' => $otherEnrollmentSubject->id,
                'score' => 80,
                'updated_by' => $this->f['professorUser']->id,
            ]);
        }

        $this->approve();
        $releaseId = $this->releaseApprovedSheet();

        foreach ([$this->enrollmentSubject, $otherEnrollmentSubject] as $completed) {
            $this->assertSame('completed', $completed->fresh()->subject_status);
            $this->assertSame('Passed', $completed->fresh()->remarks);
            $this->assertNotNull($completed->fresh()->final_grade);
        }
        $this->assertSame('enrolled', $unrelated->fresh()->subject_status);
        $this->assertSame('In Progress', $unrelated->fresh()->remarks);

        $this->f['subjects'][1]->update(['prerequisite_subject_id' => $this->f['subjects'][0]->id]);
        $this->f['application']->update(['classification' => 'irregular']);
        $validated = app(EnrollmentSubjectPolicy::class)->validate($this->f['application']->fresh(), [$this->f['subjects'][1]->id], false);
        $this->assertSame([$this->f['subjects'][1]->id], $validated->pluck('id')->all());

        $publishedVersion = $this->sheet->fresh()->version;
        app(GradingWorkflowService::class)->executeRelease(GradeReleaseSchedule::findOrFail($releaseId), $this->f['registrar'], true);
        $this->assertSame($publishedVersion, $this->sheet->fresh()->version);
        $this->assertSame(1, GradeAuditEvent::where('action', 'grade_sheet.published')->count());
    }

    public function test_published_failing_result_is_recorded_but_does_not_satisfy_prerequisites(): void
    {
        $this->configureOfficialOutcomes();
        GradeScore::query()->update(['score' => 50]);
        $this->approve();
        $this->releaseApprovedSheet();

        $this->assertSame('completed', $this->enrollmentSubject->fresh()->subject_status);
        $this->assertSame('Failed', $this->enrollmentSubject->fresh()->remarks);
        $this->assertSame(50.0, $this->enrollmentSubject->fresh()->final_grade);

        $this->f['subjects'][1]->update(['prerequisite_subject_id' => $this->f['subjects'][0]->id]);
        $this->f['application']->update(['classification' => 'irregular']);
        $this->expectException(HttpException::class);
        app(EnrollmentSubjectPolicy::class)->validate($this->f['application']->fresh(), [$this->f['subjects'][1]->id], false);
    }

    public function test_published_incomplete_result_remains_uncompleted_and_does_not_satisfy_prerequisites(): void
    {
        GradingScale::create(['min_percentage' => 0, 'max_percentage' => 100, 'grade_point' => 4, 'remark' => 'INCOMPLETE', 'is_active' => true]);
        $this->approve();
        $this->releaseApprovedSheet();

        $subject = $this->enrollmentSubject->fresh();
        $this->assertSame('enrolled', $subject->subject_status);
        $this->assertSame('Incomplete', $subject->remarks);
        $this->assertSame(86.6, $subject->final_grade);
        $this->assertNotContains($subject->subject_id, app(EnrollmentSubjectPolicy::class)->completed($this->f['application']->fresh(), false));
    }

    public function test_submission_and_approval_do_not_change_academic_completion(): void
    {
        $this->configureOfficialOutcomes();
        $this->approve();

        $subject = $this->enrollmentSubject->fresh();
        $this->assertSame('enrolled', $subject->subject_status);
        $this->assertNull($subject->final_grade);
        $this->assertSame('In Progress', $subject->remarks);
    }

    public function test_corrected_resubmission_uses_only_the_latest_approved_snapshot(): void
    {
        $this->configureOfficialOutcomes();
        $submitted = $this->submit();
        $this->registrarStepUp();
        $returned = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/return', [
            'version' => $submitted['version'],
            'reason' => 'Correct the official result.',
        ])->assertOk()->json('data');

        GradeScore::query()->update(['score' => 50]);
        Sanctum::actingAs($this->f['professorUser']);
        $corrected = $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $returned['version']])->assertOk()->json('data');
        $this->registrarStepUp();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/reviews/'.$this->sheet->id.'/approve', ['version' => $corrected['version']])->assertOk();
        $this->releaseApprovedSheet();

        $this->assertDatabaseCount('grade_submission_attempts', 2);
        $this->assertSame('Failed', $this->enrollmentSubject->fresh()->remarks);
        $this->assertSame(50.0, $this->enrollmentSubject->fresh()->final_grade);
    }

    public function test_publication_rolls_back_when_snapshot_has_no_authoritative_outcome(): void
    {
        $this->approve();
        $payload = ['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'release_at' => now()->subMinute()->toIso8601String(), 'grade_sheet_ids' => [$this->sheet->id]];
        $release = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases', $payload)->assertCreated()->json('data');

        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/releases/'.$release['id'].'/execute')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Every published result requires an official PASSED, FAILED, or INCOMPLETE outcome.');

        $this->assertSame('approved', $this->sheet->fresh()->status);
        $this->assertSame('scheduled', GradeReleaseSchedule::findOrFail($release['id'])->status);
        $this->assertSame('enrolled', $this->enrollmentSubject->fresh()->subject_status);
        $this->assertNull($this->enrollmentSubject->fresh()->final_grade);
        $this->assertDatabaseMissing('grade_audit_events', ['action' => 'grade_sheet.published']);
    }
}

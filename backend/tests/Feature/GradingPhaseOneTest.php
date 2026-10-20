<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradePeriodSchedule;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use App\Services\Grading\GradingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class GradingPhaseOneTest extends TestCase
{
    private array $f;

    private SectionSubject $assignment;

    private EnrollmentSubject $enrollmentSubject;

    private array $desktop = ['X-CDM-Client' => 'desktop'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->f = EnrollmentAcademicFixture::create('grading');
        $this->assignment = SectionSubject::create(['section_id' => $this->f['section']->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id]);
        $enrollment = Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
        $this->enrollmentSubject = EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id, 'subject_status' => 'enrolled']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function period(array $overrides = []): GradePeriodSchedule
    {
        return GradePeriodSchedule::create(array_replace(['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'midterm_opens_at' => now()->subHour(), 'midterm_deadline' => now()->addHour(), 'finals_opens_at' => now()->addDay(), 'finals_deadline' => now()->addDays(2), 'created_by' => $this->f['registrar']->id, 'updated_by' => $this->f['registrar']->id], $overrides));
    }

    private function workspace(): array
    {
        Sanctum::actingAs($this->f['professorUser']);

        return $this->postJson('/api/grading/classes/'.$this->assignment->id.'/workspace')->assertOk()->json('data');
    }

    public function test_staff_manage_term_periods_with_validation_and_optimistic_locking(): void
    {
        Sanctum::actingAs($this->f['registrar']);
        $payload = ['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'midterm_opens_at' => now()->subDay()->toIso8601String(), 'midterm_deadline' => now()->addDay()->toIso8601String(), 'finals_opens_at' => now()->addDays(2)->toIso8601String(), 'finals_deadline' => now()->addDays(3)->toIso8601String()];
        $this->postJson('/api/grading/periods', $payload, $this->desktop)->assertCreated()->assertJsonPath('data.midterm_state.state', 'Open')->assertJsonPath('data.finals_state.state', 'Upcoming');
        $this->getJson('/api/grading/periods', $this->desktop)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.periods.total', 1)
            ->assertJsonPath('data.periods.data.0.id', 1)
            ->assertJsonPath('data.periods.data.0.academic_year.id', $this->f['year']->id)
            ->assertJsonPath('data.periods.data.0.semester.id', $this->f['semester']->id)
            ->assertJsonPath('data.periods.data.0.midterm_state.state', 'Open')
            ->assertJsonPath('data.periods.data.0.finals_state.state', 'Upcoming')
            ->assertJsonStructure(['data' => ['periods' => ['data' => [['id', 'version', 'status', 'academic_year', 'semester', 'midterm_opens_at', 'midterm_deadline', 'finals_opens_at', 'finals_deadline', 'midterm_state', 'finals_state']]]]]);
        $this->postJson('/api/grading/periods', $payload, $this->desktop)->assertUnprocessable()->assertJsonPath('errors.academic_year_id.0', 'A grade period schedule already exists for the selected academic term.');
        $this->putJson('/api/grading/periods/1', $payload + ['version' => 99], $this->desktop)->assertConflict();
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grading_period.created']);

        $equalDates = array_replace($payload, [
            'midterm_deadline' => $payload['midterm_opens_at'],
            'version' => 1,
        ]);
        $this->putJson('/api/grading/periods/1', $equalDates, $this->desktop)
            ->assertUnprocessable()
            ->assertJsonPath('errors.midterm_deadline.0', 'The Midterm deadline must be after its opening date.');

        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/grading/periods')->assertOk();
        $this->putJson('/api/grading/periods/1', array_replace($payload, ['midterm_deadline' => now()->subDays(2)->toIso8601String(), 'version' => 1]))->assertUnprocessable();
    }

    public function test_desktop_period_index_accepts_an_empty_filter_and_create_errors_name_the_fields(): void
    {
        Sanctum::actingAs($this->f['registrar']);

        $this->getJson('/api/grading/periods', $this->desktop)
            ->assertOk()
            ->assertJsonPath('data.periods.total', 0)
            ->assertJsonStructure(['data' => ['academic_years', 'semesters']]);

        $this->postJson('/api/grading/periods', [], $this->desktop)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('errors.academic_year_id.0', 'Select an academic year.')
            ->assertJsonPath('errors.semester_id.0', 'Select a semester.')
            ->assertJsonPath('errors.midterm_opens_at.0', 'Enter when Midterm grading opens.')
            ->assertJsonPath('errors.finals_opens_at.0', 'Enter when Finals grading opens.');
    }

    public function test_registrar_edits_the_same_schedule_and_professor_immediately_sees_finals_open(): void
    {
        Carbon::setTestNow('2026-10-07 12:00:00');
        Sanctum::actingAs($this->f['registrar']);
        $payload = [
            'academic_year_id' => $this->f['year']->id,
            'semester_id' => $this->f['semester']->id,
            'midterm_opens_at' => now()->subDay()->toIso8601String(),
            'midterm_deadline' => now()->addHour()->toIso8601String(),
            'finals_opens_at' => '2026-11-05T08:00:00+08:00',
            'finals_deadline' => '2026-11-06T17:00:00+08:00',
        ];
        $created = $this->postJson('/api/grading/periods', $payload, $this->desktop)
            ->assertCreated()
            ->assertJsonPath('data.finals_state.state', 'Upcoming')
            ->json('data');

        $updated = $this->putJson('/api/grading/periods/'.$created['id'], array_replace($payload, [
            'finals_opens_at' => now()->subMinute()->toIso8601String(),
            'finals_deadline' => now()->addDay()->toIso8601String(),
            'version' => $created['version'],
        ]), $this->desktop)
            ->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.finals_state.state', 'Open')
            ->assertJsonPath('data.finals_state.editable', true)
            ->json('data');

        $this->assertDatabaseCount('grade_period_schedules', 1);
        $this->assertSame($created['id'], $updated['id']);

        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson('/api/grading/classes')
            ->assertOk()
            ->assertJsonPath('data.data.0.schedule.finals_state.state', 'Open');
        $workspace = $this->postJson('/api/grading/classes/'.$this->assignment->id.'/workspace')
            ->assertOk()
            ->assertJsonPath('data.schedule.finals_state.editable', true)
            ->json('data');
        $this->postJson('/api/grading/sheets/'.$workspace['sheet']['id'].'/assessments', [
            'period' => 'finals',
            'label' => 'Finals test assessment',
            'category' => 'Quiz',
            'max_score' => 20,
            'display_order' => 1,
        ])->assertCreated();
    }

    public function test_professor_bulk_assessment_entry_is_atomic_owned_and_duplicate_safe(): void
    {
        $this->period();
        $workspace = $this->workspace();
        $url = '/api/grading/sheets/'.$workspace['sheet']['id'].'/assessments/bulk';
        $payload = ['period' => 'midterm', 'assessments' => [
            ['label' => 'Quiz 1', 'category' => 'Quiz', 'max_score' => 20, 'display_order' => 1],
            ['label' => 'Laboratory 1', 'category' => 'Activity', 'max_score' => 50, 'display_order' => 2],
        ]];

        $this->postJson($url, $payload)->assertCreated()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('grade_assessments', 2);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'assessments.bulk_created']);
        $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('grade_assessments', 2);

        $duplicatePayload = ['period' => 'midterm', 'assessments' => [
            ['label' => 'Quiz 2', 'category' => 'Quiz', 'max_score' => 10, 'display_order' => 3],
            ['label' => 'quiz 2', 'category' => 'Quiz', 'max_score' => 10, 'display_order' => 4],
        ]];
        $this->postJson($url, $duplicatePayload)->assertUnprocessable();
        $this->assertDatabaseMissing('grade_assessments', ['label' => 'Quiz 2']);

        Sanctum::actingAs($this->f['user']);
        $this->postJson($url, $payload)->assertForbidden();
    }

    public function test_unused_schedule_deletes_but_used_and_locked_history_is_preserved(): void
    {
        Sanctum::actingAs($this->f['registrar']);
        $period = $this->period();
        $this->deleteJson('/api/grading/periods/'.$period->id, ['version' => $period->version, 'confirmed' => true], $this->desktop)
            ->assertOk();
        $this->assertDatabaseCount('grade_period_schedules', 0);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grading_period.deleted']);

        $period = $this->period();
        Sanctum::actingAs($this->f['professorUser']);
        $workspace = $this->workspace();
        $sheet = GradeSheet::findOrFail($workspace['sheet']['id']);
        $sheet->update(['status' => 'published', 'published_at' => now()]);

        Sanctum::actingAs($this->f['registrar']);
        $this->deleteJson('/api/grading/periods/'.$period->id, ['version' => $period->version, 'confirmed' => true], $this->desktop)
            ->assertConflict()
            ->assertJsonPath('message', 'This grade period cannot be deleted because grading activity already exists. Edit the schedule instead.');
        $this->putJson('/api/grading/periods/'.$period->id, [
            'academic_year_id' => $period->academic_year_id,
            'semester_id' => $period->semester_id,
            'midterm_opens_at' => now()->subDays(2)->toIso8601String(),
            'midterm_deadline' => now()->addDay()->toIso8601String(),
            'finals_opens_at' => now()->addDays(2)->toIso8601String(),
            'finals_deadline' => now()->addDays(3)->toIso8601String(),
            'version' => $period->version,
        ], $this->desktop)
            ->assertConflict()
            ->assertJsonPath('message', 'Grade period dates cannot be changed because submitted, approved, or published grading history already exists.');
        $this->assertDatabaseHas('grade_period_schedules', ['id' => $period->id]);
        $this->assertDatabaseHas('grade_sheets', ['id' => $sheet->id, 'status' => 'published']);

        $this->withHeader('X-CDM-Client', 'web')
            ->deleteJson('/api/grading/periods/'.$period->id, ['version' => $period->version, 'confirmed' => true])
            ->assertForbidden();
    }

    public function test_professor_window_and_assessment_use_grade_period_schedule_not_legacy_periods(): void
    {
        DB::table('grading_periods')->insert([
            'period_name' => 'Legacy Midterm',
            'period_order' => 9,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson('/api/grading/classes')
            ->assertOk()
            ->assertJsonPath('data.data.0.schedule', null);

        Sanctum::actingAs($this->f['registrar']);
        $this->postJson('/api/grading/periods', [
            'academic_year_id' => $this->f['year']->id,
            'semester_id' => $this->f['semester']->id,
            'midterm_opens_at' => now()->subHour()->toIso8601String(),
            'midterm_deadline' => now()->addHour()->toIso8601String(),
            'finals_opens_at' => now()->addDay()->toIso8601String(),
            'finals_deadline' => now()->addDays(2)->toIso8601String(),
        ], $this->desktop)
            ->assertCreated()
            ->assertJsonPath('data.midterm_state.state', 'Open')
            ->assertJsonPath('data.midterm_state.editable', true)
            ->assertJsonPath('data.finals_state.state', 'Upcoming');

        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson('/api/grading/classes')
            ->assertOk()
            ->assertJsonPath('data.data.0.schedule.midterm_state.state', 'Open')
            ->assertJsonPath('data.data.0.schedule.midterm_state.editable', true);
        $workspace = $this->workspace();
        $this->postJson('/api/grading/sheets/'.$workspace['sheet']['id'].'/assessments', [
            'period' => 'midterm',
            'label' => 'Window verification',
            'category' => 'Quiz',
            'max_score' => 10,
            'display_order' => 1,
        ])->assertCreated();
    }

    public function test_period_boundaries_are_inclusive_at_open_and_exclusive_at_deadline(): void
    {
        $period = $this->period(['midterm_opens_at' => '2026-10-03 10:00:00', 'midterm_deadline' => '2026-10-03 11:00:00']);
        $service = app(GradingService::class);
        $this->assertSame('Upcoming', $service->periodState($period, 'midterm', Carbon::parse('2026-10-03 09:59:59'))['state']);
        $this->assertSame('Open', $service->periodState($period, 'midterm', Carbon::parse('2026-10-03 10:00:00'))['state']);
        $this->assertSame('Open', $service->periodState($period, 'midterm', Carbon::parse('2026-10-03 10:30:00'))['state']);
        $this->assertSame('Closed', $service->periodState($period, 'midterm', Carbon::parse('2026-10-03 11:00:00'))['state']);
        $this->assertSame('Closed', $service->periodState($period, 'midterm', Carbon::parse('2026-10-03 11:00:01'))['state']);
    }

    public function test_professor_only_sees_own_classes_and_roster_uses_finalized_subject_enrollment(): void
    {
        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson('/api/grading/classes')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.student_count', 1);
        $workspace = $this->workspace();
        $this->assertCount(1, $workspace['roster']);
        $this->assertSame($this->enrollmentSubject->id, $workspace['roster'][0]['enrollment_subject_id']);
        $this->assertDatabaseCount('grade_sheets', 1);
        $this->assertCount(8, $workspace['sheet']['weights']);
        $this->workspace();
        $this->assertDatabaseCount('grade_sheets', 1);

        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $profile = $otherUser->profile()->create(['first_name' => 'Other', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        Professor::create(['user_id' => $otherUser->id, 'user_profile_id' => $profile->id, 'department_id' => $this->f['department']->id, 'employee_number' => 'GRADE-OTHER', 'status' => 'active']);
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/grading/classes')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/grading/classes/'.$this->assignment->id.'/workspace')->assertNotFound();
    }

    public function test_assessment_and_scores_are_validated_archived_and_stale_safe(): void
    {
        $this->period();
        $workspace = $this->workspace();
        $sheet = $workspace['sheet']['id'];
        $assessment = $this->postJson("/api/grading/sheets/{$sheet}/assessments", ['period' => 'midterm', 'label' => 'Q1', 'category' => 'Quiz', 'max_score' => 20, 'display_order' => 1])->assertCreated()->json('data');
        $this->postJson("/api/grading/sheets/{$sheet}/assessments", ['period' => 'midterm', 'label' => 'Broken', 'category' => 'Quiz', 'max_score' => 0, 'display_order' => 2])->assertUnprocessable();
        $this->putJson("/api/grading/sheets/{$sheet}/scores", ['assessment_id' => $assessment['id'], 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 18]]])->assertOk()->assertJsonPath('data.0.score', 18);
        $this->putJson("/api/grading/sheets/{$sheet}/scores", ['assessment_id' => $assessment['id'], 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => -1]]])->assertUnprocessable();
        $this->putJson("/api/grading/sheets/{$sheet}/scores", ['assessment_id' => $assessment['id'], 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 21]]])->assertUnprocessable();
        $this->putJson("/api/grading/sheets/{$sheet}/scores", ['assessment_id' => $assessment['id'], 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 17, 'version' => 99]]])->assertConflict();
        $this->patchJson("/api/grading/sheets/{$sheet}/assessments/{$assessment['id']}/status", ['version' => 1, 'status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseHas('grade_scores', ['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 18]);
    }

    public function test_closed_window_non_enrolled_and_foreign_assessment_writes_are_denied(): void
    {
        $this->period(['midterm_opens_at' => now()->addHour(), 'midterm_deadline' => now()->addHours(2)]);
        $workspace = $this->workspace();
        $sheet = $workspace['sheet']['id'];
        $this->postJson("/api/grading/sheets/{$sheet}/assessments", ['period' => 'midterm', 'label' => 'Q1', 'category' => 'Quiz', 'max_score' => 20, 'display_order' => 1])->assertConflict();
        $this->f['year']->refresh();
        GradePeriodSchedule::query()->update(['midterm_opens_at' => now()->subHour(), 'midterm_deadline' => now()->addHour()]);
        $assessment = $this->postJson("/api/grading/sheets/{$sheet}/assessments", ['period' => 'midterm', 'label' => 'Q1', 'category' => 'Quiz', 'max_score' => 20, 'display_order' => 1])->assertCreated()->json('data');
        $this->putJson("/api/grading/sheets/{$sheet}/scores", ['assessment_id' => $assessment['id'], 'scores' => [['enrollment_subject_id' => 999999, 'score' => 10]]])->assertUnprocessable();
        GradePeriodSchedule::query()->update(['midterm_deadline' => now()]);
        $this->putJson("/api/grading/sheets/{$sheet}/scores", ['assessment_id' => $assessment['id'], 'scores' => [['enrollment_subject_id' => $this->enrollmentSubject->id, 'score' => 10]]])->assertConflict();
    }

    public function test_students_guests_and_wrong_platforms_are_blocked(): void
    {
        Sanctum::actingAs($this->f['user']);
        $this->getJson('/api/grading/classes')->assertForbidden();
        $this->getJson('/api/grading/periods')->assertForbidden();
        $guest = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::GUEST])->id]);
        Sanctum::actingAs($guest);
        $this->getJson('/api/grading/classes')->assertForbidden();
        Sanctum::actingAs($this->f['professorUser']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/classes')->assertOk();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/grading/classes')
            ->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/classes')->assertForbidden();
        Sanctum::actingAs($this->f['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/periods')->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/classes')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/periods')->assertForbidden();
    }
}

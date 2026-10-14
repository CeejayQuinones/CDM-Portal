<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeSheet;
use App\Models\GradeSubmissionAttempt;
use App\Models\GradeSubmissionStudent;
use App\Models\MonitoringInterventionEvent;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\User;
use App\Services\EarlyWarningService;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class MonitoringPhaseTwoTest extends TestCase
{
    private array $fixture;

    /** @var array<string, Student> */
    private array $students;

    /** @var array<string, User> */
    private array $studentUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('monitoring-two');
        $this->students = ['high' => $this->fixture['student']];
        $this->studentUsers = ['high' => $this->fixture['user']];
        foreach (['moderate', 'stable', 'declining'] as $level) {
            [$this->students[$level], $this->studentUsers[$level]] = $this->student(ucfirst($level), 'MON2-'.strtoupper(substr($level, 0, 3)));
        }

        $assignment = SectionSubject::create([
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
        ]);
        $sheet = GradeSheet::create([
            'section_subject_id' => $assignment->id,
            'professor_id' => $this->fixture['professor']->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $attempt = GradeSubmissionAttempt::create([
            'grade_sheet_id' => $sheet->id,
            'attempt_number' => 1,
            'submitted_by' => $this->fixture['professorUser']->id,
            'submitted_at' => now(),
            'sheet_version' => 1,
            'midterm_weight' => 40,
            'finals_weight' => 60,
            'configuration' => ['source' => 'monitoring-phase-two-test'],
            'checksum' => str_repeat('2', 64),
        ]);
        $grades = [
            'high' => [95, 70, 80],
            'moderate' => [90, 80, 84],
            'stable' => [80, 86, 84],
            'declining' => [90, 84, 87],
        ];
        foreach ($this->students as $level => $student) {
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'section_id' => $this->fixture['section']->id,
                'academic_year_id' => $this->fixture['year']->id,
                'semester_id' => $this->fixture['semester']->id,
                'enrollment_date' => today(),
                'status' => 'enrolled',
            ]);
            $subject = EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'subject_id' => $this->fixture['subjects'][0]->id,
                'professor_id' => $this->fixture['professor']->id,
                'subject_status' => 'enrolled',
            ]);
            [$midterm, $finals, $final] = $grades[$level];
            GradeSubmissionStudent::create([
                'grade_submission_attempt_id' => $attempt->id,
                'enrollment_subject_id' => $subject->id,
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'student_name' => ucfirst($level).' Student',
                'midterm_grade' => $midterm,
                'finals_grade' => $finals,
                'final_grade' => $final,
                'breakdown' => [],
            ]);
        }
    }

    public function test_deterministic_study_plans_vary_for_high_moderate_stable_and_insufficient_assessments(): void
    {
        $service = app(EarlyWarningService::class);
        $high = $service->generateStudyPlan($this->assessment('high', 'high', 'declining'));
        $moderate = $service->generateStudyPlan($this->assessment('moderate', 'moderate', 'declining'));
        $stable = $service->generateStudyPlan($this->assessment('stable', 'stable', 'improving'));
        $insufficient = $service->generateStudyPlan($this->assessment('insufficient', null, 'insufficient'));

        $this->assertSame('Suggested Academic Support Plan', $high['plan_label']);
        $this->assertSame('Recovery support', $high['plan_type']);
        $this->assertSame('AC1', $high['focus_subjects'][0]['subject_code']);
        $this->assertGreaterThan($moderate['total_hours'], $high['total_hours']);
        $this->assertSame('Targeted support', $moderate['plan_type']);
        $this->assertSame('Maintenance support', $stable['plan_type']);
        $this->assertSame(3, $stable['session_count']);
        $this->assertSame('Preliminary support', $insufficient['plan_type']);
        $this->assertSame([], $insufficient['focus_subjects']);
        $this->assertSame(2, $insufficient['session_count']);
        $this->assertStringContainsString('More Published grade data', $insufficient['headline']);
        $this->assertNotSame($high['week'][0]['duration_minutes'], $stable['week'][0]['duration_minutes']);
    }

    public function test_study_plan_endpoints_preserve_student_self_and_professor_scope(): void
    {
        Sanctum::actingAs($this->studentUsers['high']);
        $this->getJson('/api/monitoring/study-plans')->assertOk()
            ->assertJsonCount(1, 'data.plans')
            ->assertJsonPath('data.plans.0.student_id', $this->students['high']->id)
            ->assertJsonPath('data.plans.0.risk_level', 'high')
            ->assertJsonPath('data.plans.0.focus_subjects.0.subject_code', $this->fixture['subjects'][0]->subject_code);
        $this->getJson('/api/monitoring/students/'.$this->students['moderate']->id.'/study-plan')->assertForbidden();

        Sanctum::actingAs($this->fixture['professorUser']);
        $this->getJson('/api/monitoring/study-plans')->assertOk()
            ->assertJsonPath('data.summary.total', 4);
        [$unrelated] = $this->student('Unrelated', 'MON2-OUT');
        $this->getJson('/api/monitoring/students/'.$unrelated->id.'/study-plan')->assertForbidden();
    }

    public function test_adviser_alerts_include_high_and_moderate_exclude_stable_and_enforce_roles(): void
    {
        Sanctum::actingAs($this->fixture['professorUser']);
        $response = $this->getJson('/api/monitoring/adviser-alerts')->assertOk()
            ->assertJsonPath('data.summary.urgent', 1)
            ->assertJsonPath('data.summary.attention', 2)
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonCount(3, 'data.alerts');
        $this->assertEqualsCanonicalizing(
            [$this->students['high']->id, $this->students['moderate']->id, $this->students['declining']->id],
            collect($response->json('data.alerts'))->pluck('student_id')->all(),
        );
        $this->assertSame('stable', collect($response->json('data.alerts'))->firstWhere('student_id', $this->students['declining']->id)['risk_level']);
        $this->assertNotContains($this->students['stable']->id, collect($response->json('data.alerts'))->pluck('student_id')->all());
        $this->assertNotEmpty($response->json('data.alerts.0.risk_reasons'));
        $this->assertArrayHasKey('published_subjects_analyzed', $response->json('data.alerts.0'));

        Sanctum::actingAs($this->studentUsers['high']);
        $this->getJson('/api/monitoring/adviser-alerts')->assertForbidden();

        Sanctum::actingAs($this->fixture['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/monitoring/adviser-alerts')->assertOk()
            ->assertJsonPath('data.summary.total', 3);
    }

    public function test_professor_sends_scoped_notice_with_safe_context_audit_and_duplicate_protection(): void
    {
        Sanctum::actingAs($this->fixture['professorUser']);
        $first = $this->postJson('/api/monitoring/students/'.$this->students['high']->id.'/risk-notifications', [])
            ->assertCreated()
            ->assertJsonPath('data.risk_level', 'high')
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.is_read', false);
        $second = $this->postJson('/api/monitoring/students/'.$this->students['high']->id.'/risk-notifications', [])
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('risk_notifications', 1);
        $notice = RiskNotification::query()->firstOrFail();
        $this->assertSame(['risk_level', 'risk_reasons', 'subject_codes', 'trend', 'monitoring_evaluated_at'], array_keys($notice->context_json));
        $this->assertStringNotContainsString('password', json_encode($notice->context_json));
        $this->assertStringNotContainsString('midterm_grade', json_encode($notice->context_json));
        $this->assertDatabaseHas('monitoring_intervention_events', [
            'risk_notification_id' => $notice->id,
            'actor_user_id' => $this->fixture['professorUser']->id,
            'student_id' => $this->students['high']->id,
            'action' => 'monitoring.risk_notice.sent',
            'risk_level' => 'high',
        ]);
        $this->assertSame(1, MonitoringInterventionEvent::query()->where('action', 'monitoring.risk_notice.sent')->count());

        [$unrelated] = $this->student('Outside', 'MON2-NOTICE-OUT');
        $this->postJson('/api/monitoring/students/'.$unrelated->id.'/risk-notifications')->assertForbidden();
    }

    public function test_registrar_and_admin_can_send_but_student_and_invalid_platforms_are_blocked(): void
    {
        Sanctum::actingAs($this->fixture['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')
            ->postJson('/api/monitoring/students/'.$this->students['moderate']->id.'/risk-notifications', ['message' => 'Please review the published trend with your Professor.'])
            ->assertCreated();
        $this->withHeader('X-CDM-Client', 'web')
            ->postJson('/api/monitoring/students/'.$this->students['stable']->id.'/risk-notifications')
            ->assertForbidden();

        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id, 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->withHeader('X-CDM-Client', 'web')
            ->postJson('/api/monitoring/students/'.$this->students['stable']->id.'/risk-notifications')
            ->assertCreated();
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson('/api/monitoring/students/'.$this->students['high']->id.'/risk-notifications')
            ->assertForbidden();

        Sanctum::actingAs($this->studentUsers['high']);
        $this->postJson('/api/monitoring/students/'.$this->students['high']->id.'/risk-notifications')
            ->assertForbidden();
    }

    public function test_student_my_notices_is_private_persistent_and_updates_unread_state(): void
    {
        Sanctum::actingAs($this->fixture['professorUser']);
        $own = $this->postJson('/api/monitoring/students/'.$this->students['high']->id.'/risk-notifications', ['message' => 'Please review your published subject signal.'])
            ->assertCreated()->json('data');
        $other = $this->postJson('/api/monitoring/students/'.$this->students['moderate']->id.'/risk-notifications', ['message' => 'Please review your published trend.'])
            ->assertCreated()->json('data');

        Sanctum::actingAs($this->studentUsers['high']);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/monitoring/my-risk-notifications')->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.id', $own['id'])
            ->assertJsonPath('data.notifications.0.sender.name', 'Academic Professor');
        $this->withHeader('X-CDM-Client', 'mobile')
            ->patchJson('/api/monitoring/risk-notifications/'.$other['id'].'/read')
            ->assertNotFound();
        $this->withHeader('X-CDM-Client', 'mobile')
            ->patchJson('/api/monitoring/risk-notifications/'.$own['id'].'/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/monitoring/my-risk-notifications')->assertOk()
            ->assertJsonPath('data.unread', 0)
            ->assertJsonPath('data.notifications.0.is_read', true);
        $this->patchJson('/api/monitoring/risk-notifications/'.$own['id'].'/read')->assertOk();
        $this->assertSame(1, MonitoringInterventionEvent::query()->where('action', 'monitoring.risk_notice.read')->count());
    }

    public function test_notice_sending_is_rate_limited_without_blocking_normal_staff_use(): void
    {
        Sanctum::actingAs($this->fixture['professorUser']);
        $url = '/api/monitoring/students/'.$this->students['high']->id.'/risk-notifications';

        foreach (range(1, 10) as $attempt) {
            $this->postJson($url, ['message' => "Academic support notice {$attempt}."])->assertCreated();
        }

        $this->postJson($url, ['message' => 'This request exceeds the configured minute limit.'])
            ->assertTooManyRequests();
        $this->assertDatabaseCount('risk_notifications', 10);
    }

    /** @return array<string, mixed> */
    private function assessment(string $level, ?string $subjectRisk, string $trend): array
    {
        $subject = $subjectRisk ? [[
            'subject_code' => 'AC1',
            'subject_name' => 'Academic subject 1',
            'risk_level' => $subjectRisk,
            'risk_label' => ucfirst($subjectRisk),
            'trend' => $trend,
            'checkpoint_change' => $trend === 'declining' ? -15 : 6,
            'midterm_grade' => 90,
            'finals_grade' => $trend === 'declining' ? 75 : 96,
            'final_grade' => 84,
            'reasons' => ['Published checkpoint evidence.'],
        ]] : [];

        return [
            'student_id' => 1,
            'student_number' => 'MON-PLAN',
            'student_name' => 'Plan Student',
            'course_code' => 'BSIT',
            'section' => 'BSIT-1A',
            'risk_level' => $level,
            'risk_label' => $level === 'insufficient' ? 'Insufficient data' : ucfirst($level),
            'trend' => $trend,
            'reasons' => $subject ? ['AC1: Published checkpoint evidence.'] : ['No published official grade snapshot is available.'],
            'subjects' => $subject,
            'data_completeness' => [
                'status' => $level === 'insufficient' ? 'no_published_data' : 'complete',
                'published_subjects' => count($subject),
                'expected_subjects' => 1,
            ],
            'evaluated_at' => '2026-10-03T12:00:00+08:00',
        ];
    }

    /** @return array{Student, User} */
    private function student(string $name, string $number): array
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id'), 'status' => 'active']);
        $profile = $user->profile()->create(['first_name' => $name, 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $student = Student::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id,
            'student_number' => $number,
            'admission_date' => today(),
            'year_level' => 1,
            'student_status' => 'regular',
        ]);

        return [$student, $user];
    }
}

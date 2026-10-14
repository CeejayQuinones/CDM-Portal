<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireStepUpAuthentication;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Admission\AdmissionAuditWriter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdmissionConversionFixture;
use Tests\TestCase;

class AdmissionHandoffTest extends TestCase
{
    private array $fixture;

    private array $case;

    private EnrollmentPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = AdmissionConversionFixture::create('handoff');
        $this->case = $this->fixture['cases'][0];
        $semester = Semester::create(['semester_name' => 'Handoff semester', 'semester_order' => 1, 'status' => 'active']);
        $this->period = EnrollmentPeriod::create([
            'academic_year_id' => $this->fixture['year']->id, 'semester_id' => $semester->id,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'enabled' => true,
            'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []],
            'created_by' => $this->fixture['registrar']->id, 'updated_by' => $this->fixture['registrar']->id,
        ]);
        Sanctum::actingAs($this->fixture['registrar']);
        $this->withoutMiddleware(RequireStepUpAuthentication::class);
    }

    private function path(string $action): string
    {
        return '/api/admission/registrar/applicants/'.$this->case['applicant']->id.'/'.$action;
    }

    private function payload(): array
    {
        return [
            'version' => $this->case['applicant']->fresh()->version,
            'result_id' => $this->case['result']->id,
            'result_version' => $this->case['result']->fresh()->version,
            'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id,
            'confirmed' => true,
        ];
    }

    public static function staffRoles(): array
    {
        return [[Role::ADMIN], [Role::REGISTRAR_STAFF]];
    }

    #[DataProvider('staffRoles')]
    public function test_admission_completion_creates_handoff_without_student_identity(string $role): void
    {
        $actor = $role === Role::REGISTRAR_STAFF ? $this->fixture['registrar']
            : User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
        Sanctum::actingAs($actor);

        $this->getJson($this->path('handoff'))->assertOk()
            ->assertJsonPath('data.can_complete', true)
            ->assertJsonPath('data.completed', false);
        $this->postJson($this->path('accept'), $this->payload())->assertOk();

        $this->assertDatabaseCount('students', 0);
        $this->assertSame(Role::GUEST, $this->case['user']->fresh('role')->role->role_name);
        $this->assertDatabaseHas('admission_applicants', [
            'id' => $this->case['applicant']->id,
            'status' => 'accepted',
            'converted_student_id' => null,
        ]);
        $this->assertDatabaseHas('admission_decisions', ['applicant_id' => $this->case['applicant']->id, 'action' => 'application_accepted']);
        $this->assertDatabaseHas('admission_workflow_events', ['applicant_id' => $this->case['applicant']->id, 'action' => 'admission.application_accepted']);
        $this->getJson($this->path('handoff'))->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.can_complete', false)
            ->assertJsonPath('data.student', null);

        Sanctum::actingAs($this->case['user']->fresh());
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.admission.completed', true)
            ->assertJsonPath('data.admission.admitted_guest', true);
        $this->getJson('/api/admission/me')->assertOk()
            ->assertJsonPath('data.application.admission_completed', true)
            ->assertJsonPath('data.application.eligible_for_enrollment', true)
            ->assertJsonPath('data.application.student_identity_created', false);
        $this->getJson('/api/enrollment/status')->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.applications_enabled', true)
            ->assertJsonPath('data.reason', 'student_identity_pending')
            ->assertJsonPath('data.admission.course.id', $this->fixture['course']->id);
        $this->postJson('/api/enrollment/applications', [
            'period_id' => $this->period->id, 'classification' => 'regular',
        ])->assertCreated()->assertJsonPath('data.student_id', null)
            ->assertJsonPath('data.owner_user_id', $this->case['user']->id)
            ->assertJsonPath('data.admission_applicant_id', $this->case['applicant']->id);
        $this->assertDatabaseCount('students', 0);
        $this->postJson('/api/admission/exam/start')->assertForbidden();
        $this->getJson('/api/grading/student/grades')->assertForbidden();
        $this->getJson('/api/monitoring/my-risk')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-promotions')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events')->assertForbidden();
    }

    public function test_completion_is_idempotent_and_removed_conversion_endpoint_cannot_create_student(): void
    {
        $this->postJson($this->path('accept'), $this->payload())->assertOk();
        $decisionCount = AdmissionDecision::where('action', 'application_accepted')->count();
        $this->postJson($this->path('accept'), $this->payload())->assertOk()
            ->assertJsonPath('data.already_completed', true);
        $this->postJson($this->path('convert'), $this->payload())->assertNotFound();
        $this->assertSame($decisionCount, AdmissionDecision::where('action', 'application_accepted')->count());
        $this->assertDatabaseCount('students', 0);
    }

    public function test_completion_uses_the_current_applications_latest_attempt_only(): void
    {
        $this->case['session']->forceFill(['attempt_number' => 2])->save();
        $cycle = $this->fixture['cycle']->replicate();
        $cycle->code = 'CV-handoff-current';
        $cycle->name = 'Current handoff cycle';
        $cycle->save();
        $applicant = new AdmissionApplicant;
        $applicant->forceFill(['user_id' => $this->case['user']->id, 'cycle_id' => $cycle->id])->save();
        $session = new AdmissionExamSession;
        $session->forceFill([
            'id' => (string) Str::uuid(), 'applicant_id' => $applicant->id,
            'attempt_number' => 1, 'status' => 'finalized', 'bank_version' => str_repeat('b', 64),
            'policy_snapshot' => [], 'started_at' => now()->subHour(),
            'deadline_at' => now()->addHour(), 'finalized_at' => now(),
        ])->save();
        $result = AdmissionExamResult::create([
            'session_id' => $session->id, 'raw_correct_count' => 80, 'question_count' => 100,
            'system_percentage' => 80, 'system_passed' => true, 'category_scores' => [],
            'category_maximums' => [], 'time_spent_seconds' => 3600, 'finalized_at' => now(),
            'finalization_cause' => 'submit', 'official_score' => 80,
            'official_status' => 'published', 'published_at' => now(), 'version' => 1,
        ]);

        $this->postJson('/api/admission/registrar/applicants/'.$applicant->id.'/accept', [
            'version' => $applicant->version,
            'result_id' => $result->id,
            'result_version' => $result->version,
            'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id,
            'confirmed' => true,
        ])->assertOk();

        $this->assertSame(AdmissionApplicant::ACCEPTED, $applicant->fresh()->status);
        $this->assertSame(AdmissionApplicant::DRAFT, $this->case['applicant']->fresh()->status);
    }

    public function test_admission_handoff_cannot_create_a_second_enrollment_application_for_another_term(): void
    {
        $this->postJson($this->path('accept'), $this->payload())->assertOk();
        Sanctum::actingAs($this->case['user']->fresh());
        $this->postJson('/api/enrollment/applications', [
            'period_id' => $this->period->id, 'classification' => 'regular',
        ])->assertCreated();

        $semester = Semester::create([
            'semester_name' => 'Second handoff semester', 'semester_order' => 2, 'status' => 'active',
        ]);
        $otherPeriod = EnrollmentPeriod::create([
            'academic_year_id' => $this->fixture['year']->id, 'semester_id' => $semester->id,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'enabled' => true,
            'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []],
            'created_by' => $this->fixture['registrar']->id, 'updated_by' => $this->fixture['registrar']->id,
        ]);

        $this->postJson('/api/enrollment/applications', [
            'period_id' => $otherPeriod->id, 'classification' => 'regular',
        ])->assertConflict()
            ->assertJsonPath('message', 'This Admission handoff already has an Enrollment application. Open your existing application.');
        $this->assertDatabaseCount('enrollment_applications', 1);
    }

    public function test_failed_unpublished_or_stale_result_cannot_complete_admission(): void
    {
        foreach ([['official_status' => 'pending'], ['official_status' => 'published', 'official_score' => 50]] as $change) {
            $this->case['result']->update($change);
            $this->postJson($this->path('accept'), $this->payload())->assertConflict();
        }
        $this->case['result']->update(['official_status' => 'published', 'official_score' => 80, 'version' => 2]);
        $payload = $this->payload();
        $payload['result_version'] = 1;
        $this->postJson($this->path('accept'), $payload)->assertConflict()
            ->assertJsonPath('code', 'ADMISSION_STATE_CHANGED');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_completion_audit_failure_rolls_back_handoff(): void
    {
        $this->app->bind(AdmissionAuditWriter::class, fn () => new class extends AdmissionAuditWriter
        {
            public function workflow(?User $actor, string $action, string $type, string|int $id, ?int $applicantId = null, array $metadata = []): void
            {
                parent::workflow($actor, $action, $type, $id, $applicantId, $metadata);
                throw new \RuntimeException('Forced audit failure');
            }
        });

        $this->postJson($this->path('accept'), $this->payload())->assertStatus(503)->assertDontSee('Forced audit failure');
        $this->assertSame('draft', $this->case['applicant']->fresh()->status);
        $this->assertDatabaseMissing('admission_decisions', ['applicant_id' => $this->case['applicant']->id, 'action' => 'application_accepted']);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_only_admission_staff_with_step_up_can_complete_handoff(): void
    {
        $this->withMiddleware(RequireStepUpAuthentication::class);
        $this->postJson($this->path('accept'), $this->payload())->assertStatus(428);
        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            Sanctum::actingAs(User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]));
            $this->postJson($this->path('accept'), $this->payload())->assertForbidden();
            $this->getJson($this->path('handoff'))->assertForbidden();
        }
    }

    public function test_existing_converted_student_remains_compatible(): void
    {
        $student = AdmissionConversionFixture::legacyStudent($this->fixture, $this->case, 'LEGACY-00001');
        $this->assertInstanceOf(Student::class, $student);
        Sanctum::actingAs($this->case['user']->fresh());
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.admission.completed', true)
            ->assertJsonPath('data.admission.admitted_guest', false);
        $this->getJson('/api/enrollment/status')->assertOk()
            ->assertJsonPath('data.student.id', $student->id);
        $this->assertSame(Role::STUDENT, $this->case['user']->fresh('role')->role->role_name);
    }
}

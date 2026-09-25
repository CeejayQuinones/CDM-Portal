<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireStepUpAuthentication;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionDecision;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Admission\AdmissionAuditWriter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdmissionConversionFixture;
use Tests\TestCase;

class AdmissionConversionTest extends TestCase
{
    private array $fixture;

    private array $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = AdmissionConversionFixture::create('unit');
        $this->case = $this->fixture['cases'][0];
        Sanctum::actingAs($this->fixture['registrar']);
        $this->withoutMiddleware(RequireStepUpAuthentication::class);
    }

    private function path(string $action): string
    {
        return '/api/admission/registrar/applicants/'.$this->case['applicant']->id.'/'.$action;
    }

    private function payload(): array
    {
        return ['version' => $this->case['applicant']->fresh()->version, 'result_id' => $this->case['result']->id,
            'result_version' => $this->case['result']->fresh()->version, 'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id, 'confirmed' => true,
            'student_number' => '26-01234', 'admission_date' => now()->toDateString()];
    }

    private function accept(): void
    {
        $this->postJson($this->path('accept'), $this->payload())->assertOk();
    }

    public function test_conversion_reuses_identity_and_preserves_history_and_credentials(): void
    {
        $user = $this->case['user'];
        $token = $user->createToken('old guest session');
        $original = $user->only(['id', 'username', 'password', 'status']);
        $users = User::count();
        $this->getJson($this->path('conversion'))->assertOk()->assertJsonPath('data.can_convert', false);
        $this->accept();
        $this->assertDatabaseCount('students', 0);
        $this->assertSame(Role::GUEST, $user->fresh('role')->role->role_name);
        $this->getJson($this->path('conversion'))->assertOk()->assertJsonPath('data.can_convert', true);
        $this->postJson($this->path('convert'), $this->payload())->assertOk()->assertJsonPath('data.student.user_id', $user->id);
        $this->assertSame($users, User::count());
        $this->assertSame($original, $user->fresh()->only(array_keys($original)));
        $this->assertSame(Role::STUDENT, $user->fresh('role')->role->role_name);
        $this->assertDatabaseHas('students', ['user_id' => $user->id, 'user_profile_id' => $this->case['profile']->id, 'course_id' => $this->fixture['course']->id, 'curriculum_id' => $this->fixture['curriculum']->id, 'student_number' => '26-01234', 'year_level' => 1, 'student_status' => 'regular']);
        $this->assertSame(now()->toDateString(), Student::first()->admission_date->toDateString());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('admission_workflow_events', ['action' => 'admission.student_converted', 'applicant_id' => $this->case['applicant']->id]);
        $this->assertDatabaseHas('admission_exam_results', ['id' => $this->case['result']->id, 'official_score' => 80]);
        $this->getJson($this->path('conversion'))->assertJsonPath('data.can_convert', false)->assertJsonPath('data.student.student_number', '26-01234');
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/admission/me')->assertOk()->assertJsonPath('data.application.is_converted', true);
        $this->getJson('/api/admission/result')->assertOk()->assertJsonPath('data.result.outcome', 'PASSED');
        $this->postJson('/api/admission/exam/start')->assertForbidden();
    }

    public function test_repeated_confirmation_returns_same_student_without_duplicate_evidence(): void
    {
        $this->accept();
        $payload = $this->payload();
        $first = $this->postJson($this->path('convert'), $payload)->assertOk()->json('data.student.student_id');
        $this->postJson($this->path('convert'), $payload)->assertOk()->assertJsonPath('data.already_converted', true)->assertJsonPath('data.student.student_id', $first);
        $this->assertDatabaseCount('students', 1);
        $this->assertSame(1, AdmissionDecision::where('action', 'student_converted')->count());
    }

    public function test_audit_failure_rolls_back_student_role_link_and_token_revocation(): void
    {
        $this->accept();
        $token = $this->case['user']->createToken('guest');
        $this->app->bind(AdmissionAuditWriter::class, fn () => new class extends AdmissionAuditWriter
        {
            public function workflow(?User $actor, string $action, string $type, string|int $id, ?int $applicantId = null, array $metadata = []): void
            {
                parent::workflow($actor, $action, $type, $id, $applicantId, $metadata);
                throw new \RuntimeException('Forced audit failure');
            }
        });
        $this->postJson($this->path('convert'), $this->payload())->assertStatus(503)->assertDontSee('Forced audit failure');
        $this->assertDatabaseCount('students', 0);
        $this->assertSame(Role::GUEST, $this->case['user']->fresh('role')->role->role_name);
        $this->assertSame('accepted', $this->case['applicant']->fresh()->status);
        $this->assertNull($this->case['applicant']->fresh()->converted_student_id);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertSame(0, AdmissionDecision::where('action', 'student_converted')->count());
        $this->assertDatabaseMissing('admission_workflow_events', ['action' => 'admission.student_converted']);
    }

    public function test_acceptance_and_latest_result_are_required(): void
    {
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        foreach ([['official_status' => 'pending'], ['official_status' => 'published', 'official_score' => 50]] as $change) {
            $this->case['result']->update($change);
            $this->postJson($this->path('accept'), $this->payload())->assertConflict();
            $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        }
        $this->assertDatabaseCount('students', 0);
    }

    public function test_active_or_unpublished_retake_prevents_conversion(): void
    {
        $this->accept();
        $session = $this->case['session']->replicate();
        $session->id = (string) Str::uuid();
        $session->attempt_number = 2;
        $session->status = 'active';
        $session->save();
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $session->update(['status' => 'finalized']);
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $this->getJson($this->path('conversion'))->assertJsonPath('data.can_convert', false);
    }

    public function test_result_correction_invalidates_acceptance_even_if_still_passed(): void
    {
        $this->accept();
        $this->case['result']->update(['version' => 2, 'official_score' => 90]);
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $this->accept();
        $this->postJson($this->path('convert'), $this->payload())->assertOk();
        $this->postJson('/api/admission/registrar/results/correct', ['results' => [['id' => $this->case['result']->id, 'version' => 2]], 'official_score' => 60, 'reason' => 'Needs separate academic review'])->assertConflict();
    }

    public function test_program_curriculum_and_number_are_validated(): void
    {
        $this->postJson($this->path('accept'), array_replace($this->payload(), ['curriculum_id' => 999]))->assertUnprocessable();
        $this->accept();
        foreach ([['student_number' => ''], ['student_number' => str_repeat('1', 21)], ['admission_date' => 'bad'], ['confirmed' => false]] as $change) {
            $this->postJson($this->path('convert'), array_replace($this->payload(), $change))->assertUnprocessable();
        }
        $this->postJson($this->path('convert'), array_replace($this->payload(), ['course_id' => 999]))->assertConflict();
        $this->fixture['curriculum']->update(['status' => 'inactive']);
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $this->assertDatabaseCount('students', 0);
    }

    public function test_duplicate_official_number_cannot_create_a_second_student(): void
    {
        $this->accept();
        $this->postJson($this->path('convert'), $this->payload())->assertOk();
        $this->case = $this->fixture['cases'][1];
        $this->accept();
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $this->assertDatabaseCount('students', 1);
        $this->assertSame(Role::GUEST, $this->case['user']->fresh('role')->role->role_name);
    }

    public function test_closed_confirmation_window_and_non_current_case_are_denied(): void
    {
        $this->accept();
        $this->fixture['cycle']->update(['opens_at' => now()->subDays(3), 'closes_at' => now()->subDays(2), 'confirmation_closes_at' => now()->subMinute()]);
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $this->fixture['cycle']->update(['confirmation_closes_at' => now()->addDay()]);
        $this->case['applicant']->forceFill(['status' => 'withdrawn'])->save();
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
    }

    public function test_newer_application_and_stale_confirmation_are_denied(): void
    {
        $this->accept();
        $payload = $this->payload();
        $this->postJson($this->path('convert'), array_replace($payload, ['version' => 1]))->assertConflict();
        $cycle = $this->fixture['cycle']->replicate();
        $cycle->code = 'NEW-CYCLE';
        $cycle->save();
        $new = new AdmissionApplicant;
        $new->forceFill(['user_id' => $this->case['user']->id, 'cycle_id' => $cycle->id])->save();
        $this->postJson($this->path('convert'), $payload)->assertConflict();
        $this->assertDatabaseCount('students', 0);
    }

    public function test_mismatched_curriculum_and_existing_academic_identity_are_not_linked(): void
    {
        $other = $this->fixture['course']->replicate();
        $other->course_code = 'OTHER';
        $other->save();
        $this->postJson($this->path('accept'), array_replace($this->payload(), ['course_id' => $other->id]))->assertUnprocessable();
        $this->accept();
        Student::create(['user_id' => $this->case['user']->id, 'user_profile_id' => $this->case['profile']->id,
            'course_id' => $this->fixture['course']->id, 'curriculum_id' => $this->fixture['curriculum']->id,
            'student_number' => 'EXISTING', 'admission_date' => now()->toDateString(), 'year_level' => 1, 'student_status' => 'regular']);
        $this->postJson($this->path('convert'), $this->payload())->assertConflict();
        $this->assertDatabaseCount('students', 1);
        $this->assertNull($this->case['applicant']->fresh()->converted_student_id);
        $this->assertSame(Role::GUEST, $this->case['user']->fresh('role')->role->role_name);
    }

    public function test_only_registrar_with_step_up_may_confirm(): void
    {
        $this->withMiddleware(RequireStepUpAuthentication::class);
        $this->postJson($this->path('convert'), $this->payload())->assertStatus(428);
        foreach ([Role::GUEST, Role::STUDENT, Role::ADMIN, Role::PROFESSOR] as $role) {
            Sanctum::actingAs(User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]));
            $this->postJson($this->path('convert'), $this->payload())->assertForbidden();
            $this->postJson($this->path('accept'), $this->payload())->assertForbidden();
            $this->getJson($this->path('conversion'))->assertForbidden();
        }
    }
}

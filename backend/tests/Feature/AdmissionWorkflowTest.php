<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireStepUpAuthentication;
use App\Models\AcademicYear;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionAuditWriter;
use App\Services\Admission\AdmissionConfigurationService;
use App\Services\Admission\AdmissionIdentityService;
use App\Services\Admission\ProgramMatcher;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionWorkflowTest extends TestCase
{
    private User $admin;

    private User $guest;

    private User $registrar;

    private AdmissionCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->admin = $this->user(Role::ADMIN);
        $this->guest = $this->user(Role::GUEST);
        $this->registrar = $this->user(Role::REGISTRAR_STAFF);
        $year = AcademicYear::create(['school_year' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'status' => 'active']);
        $this->cycle = app(AdmissionConfigurationService::class)->cycle($this->admin, [
            'code' => 'TEST', 'name' => 'Test intake', 'academic_year_id' => $year->id, 'status' => 'open',
            'opens_at' => now()->subDay()->toIso8601String(), 'closes_at' => now()->addDay()->toIso8601String(), 'confirmation_closes_at' => now()->addDays(2)->toIso8601String(),
        ]);
        app(AdmissionIdentityService::class)->create($this->guest, $this->cycle);
    }

    public function test_dev_setup_is_manual_duplicate_safe_and_cleanup_preserves_real_records(): void
    {
        $this->cycle->update(['status' => 'closed']);
        $students = DB::table('students')->count();
        $this->artisan('admission:dev-setup')->assertExitCode(1);
        $this->artisan('admission:dev-setup', ['--dummy-bank' => true])->assertExitCode(0);
        $this->artisan('admission:dev-setup', ['--dummy-bank' => true])->assertExitCode(0);
        $this->assertDatabaseCount('admission_exam_questions', 100);
        $this->assertSame(1, AdmissionCycle::where('code', 'DEV-MVP')->count());
        foreach (ProgramMatcher::INTEREST_CATEGORIES as $topic) {
            $this->assertSame(20, AdmissionExamQuestion::where('topic', $topic)->where('status', 'active')->count());
        }
        $this->bank();
        $this->artisan('admission:dev-setup', ['--dummy-bank' => true])->assertExitCode(1);
        $this->artisan('admission:dev-setup', ['--cleanup' => true])->assertExitCode(0);
        $this->assertSame(110, AdmissionExamQuestion::where('question_code', 'like', 'TEST-%')->count());
        $this->assertSame(0, AdmissionExamQuestion::where('question_code', 'like', 'DEV-MVP-%')->count());
        $this->assertDatabaseCount('students', $students);
    }

    public function test_production_refuses_dummy_setup_and_excludes_dummy_questions(): void
    {
        $this->cycle->update(['status' => 'closed']);
        $this->artisan('admission:dev-setup', ['--dummy-bank' => true])->assertExitCode(0);
        $this->app['env'] = 'production';
        $this->artisan('admission:dev-setup', ['--dummy-bank' => true])->assertExitCode(1);
        Sanctum::actingAs($this->guest);
        $this->getJson('/api/admission/exam')->assertOk()->assertJsonPath('data.reason', 'bank_incomplete');
        $this->postJson('/api/admission/exam/start')->assertStatus(409);
        $this->assertDatabaseCount('admission_exam_sessions', 0);
    }

    public function test_same_day_overlap_from_browser_iso_dates_is_rejected(): void
    {
        $this->cycle->update(['opens_at' => '2026-10-01 08:00:00', 'closes_at' => '2026-10-01 18:00:00', 'confirmation_closes_at' => '2026-10-02 18:00:00']);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admission/admin/cycles', [
            'code' => 'OVERLAP', 'name' => 'Overlap', 'academic_year_id' => $this->cycle->academic_year_id, 'status' => 'open',
            'opens_at' => '2026-10-01T09:00:00.000Z', 'closes_at' => '2026-10-01T17:00:00.000Z', 'confirmation_closes_at' => '2026-10-02T18:00:00.000Z',
        ])->assertStatus(409);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
        $user->profile()->create(['first_name' => 'Test', 'last_name' => 'Applicant', 'gender' => 'Prefer not to say']);

        return $user;
    }

    private function bank(): void
    {
        foreach (ProgramMatcher::INTEREST_CATEGORIES as $index => $topic) {
            for ($i = 0; $i < 22; $i++) {
                AdmissionExamQuestion::create(['question_code' => 'TEST-'.$index.'-'.$i, 'topic' => $topic, 'question_text' => 'Test '.$index.'-'.$i,
                    'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D', 'correct_answer' => 'A', 'difficulty' => 'easy', 'status' => 'active', 'version' => 1,
                    'created_by_user_id' => $this->admin->id, 'updated_by_user_id' => $this->admin->id]);
            }
        }
    }

    private function start(): array
    {
        Sanctum::actingAs($this->guest);

        return $this->postJson('/api/admission/exam/start')->assertOk()->json('data');
    }

    private function answers(array $session, string $answer = 'A'): array
    {
        return ['revision' => $session['revision'], 'position' => 0, 'answers' => array_fill_keys(array_column($session['questions'], 'id'), $answer)];
    }

    private function submit(array $session, string $answer = 'A'): AdmissionExamResult
    {
        Sanctum::actingAs($this->guest);
        $this->postJson('/api/admission/exam/'.$session['session_id'].'/submit', $this->answers($session, $answer))->assertOk()->assertJsonPath('data.exam_completed', true);

        return AdmissionExamResult::where('session_id', $session['session_id'])->firstOrFail();
    }

    private function act(AdmissionExamResult $result, string $action, array $extra = []): void
    {
        Sanctum::actingAs($this->registrar);
        $this->postJson('/api/admission/registrar/results/'.$action, ['results' => [['id' => $result->id, 'version' => $result->fresh()->version]]] + $extra)->assertOk();
    }

    public function test_cycle_dates_overlap_roles_versions_and_audit(): void
    {
        Sanctum::actingAs($this->admin);
        $body = $this->cycle->toArray();
        $body['code'] = 'OTHER';
        $this->postJson('/api/admission/admin/cycles', $body)->assertStatus(409);
        $body['closes_at'] = $body['opens_at'];
        $this->postJson('/api/admission/admin/cycles', $body)->assertStatus(422);
        $body = $this->cycle->toArray() + ['expected_updated_at' => $this->cycle->updated_at->toISOString()];
        $body['status'] = 'closed';
        $this->putJson('/api/admission/admin/cycles/'.$this->cycle->id, $body)->assertOk();
        $this->putJson('/api/admission/admin/cycles/'.$this->cycle->id, $body)->assertStatus(409);
        $this->assertDatabaseHas('admission_workflow_events', ['action' => 'admission.cycle_closed']);
        Sanctum::actingAs($this->registrar);
        $this->getJson('/api/admission/admin/cycles')->assertForbidden();
    }

    public function test_start_resume_snapshot_order_and_stale_revision(): void
    {
        $this->freezeTime();
        $this->bank();
        $session = $this->start();
        $this->assertCount(100, $session['questions']);
        $this->assertSame(7200, strtotime($session['deadline']) - strtotime($session['server_now']));
        foreach (array_count_values(array_column($session['questions'], 'topic')) as $count) {
            $this->assertSame(20, $count);
        }
        $this->assertStringNotContainsString('correct_answer', json_encode($session));
        AdmissionExamQuestion::query()->update(['question_text' => 'CHANGED', 'correct_answer' => 'D']);
        $this->assertSame($session['questions'], $this->start()['questions']);
        $this->assertSame($session['session_id'], $this->start()['session_id']);
        $body = $this->answers($session);
        $this->putJson('/api/admission/exam/'.$session['session_id'].'/answers', $body)->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson('/api/admission/exam/'.$session['session_id'].'/answers', $body)->assertStatus(409);
        $this->getJson('/api/admission/exam')->assertOk()->assertJsonPath('data.session.revision', 1);
        $body['revision'] = 1;
        $this->postJson('/api/admission/exam/'.$session['session_id'].'/submit', $body)->assertOk();
        $this->assertSame(100, AdmissionExamResult::sole()->raw_correct_count);
        $this->postJson('/api/admission/exam/'.$session['session_id'].'/submit', $body)->assertOk();
        $this->assertDatabaseCount('admission_exam_results', 1);
    }

    public function test_expiry_ignores_late_answers_and_scheduler_is_idempotent(): void
    {
        $this->bank();
        $session = $this->start();
        $this->travel(121)->minutes();
        $this->putJson('/api/admission/exam/'.$session['session_id'].'/answers', $this->answers($session))->assertOk()->assertJsonPath('data.expired', true);
        $this->assertSame(0, AdmissionExamResult::sole()->raw_correct_count);
        $this->artisan('admission:finalize-expired')->assertExitCode(0);
        $this->assertDatabaseCount('admission_exam_results', 1);
        $this->assertDatabaseHas('admission_workflow_events', ['action' => 'admission.exam_expired', 'actor_user_id' => null]);
        $this->travelBack();
    }

    public function test_abandoned_session_is_finalized_by_scheduler(): void
    {
        $this->bank();
        $this->start();
        $this->travel(121)->minutes();
        $this->artisan('admission:finalize-expired')->assertExitCode(0);
        $this->assertSame('deadline', AdmissionExamResult::sole()->finalization_cause);
        $this->travelBack();
    }

    public function test_publication_retake_limit_and_unpublished_visibility(): void
    {
        $this->bank();
        $first = $this->start();
        $result = $this->submit($first, 'B');
        $this->getJson('/api/admission/result')->assertOk()->assertJsonPath('data.result', null);
        $this->getJson('/api/admission/recommendation')->assertForbidden();
        $this->postJson('/api/admission/exam/start')->assertStatus(409);
        $this->act($result, 'approve');
        Sanctum::actingAs($this->guest);
        $this->getJson('/api/admission/result')->assertJsonPath('data.published', false);
        $this->act($result, 'publish');
        Sanctum::actingAs($this->guest);
        $this->getJson('/api/admission/result')->assertJsonPath('data.result.outcome', 'RETAKE')->assertJsonPath('data.result.retake_eligible', true);
        $second = $this->start();
        $this->assertSame(2, $second['attempt_number']);
        $this->assertNotSame(array_column($first['questions'], 'id'), array_column($second['questions'], 'id'));
        $result2 = $this->submit($second, 'B');
        $this->act($result2, 'approve');
        $this->act($result2, 'publish');
        Sanctum::actingAs($this->guest);
        $this->getJson('/api/admission/result')->assertJsonPath('data.result.outcome', 'FAILED');
        $this->postJson('/api/admission/exam/start')->assertStatus(409);
        $this->assertDatabaseCount('admission_exam_sessions', 2);
    }

    public function test_review_stale_batch_rollback_correction_and_override_redaction(): void
    {
        $this->withoutMiddleware(RequireStepUpAuthentication::class);
        $this->bank();
        $result = $this->submit($this->start(), 'B');
        $this->act($result, 'approve');
        $this->act($result, 'publish');
        Sanctum::actingAs($this->registrar);
        $this->postJson('/api/admission/registrar/results/correct', ['results' => [['id' => $result->id, 'version' => 1]], 'official_score' => 80, 'reason' => 'Correction'])->assertStatus(409);
        $second = $this->start();
        Sanctum::actingAs($this->registrar);
        $this->postJson('/api/admission/registrar/results/correct', ['results' => [['id' => $result->id, 'version' => $result->fresh()->version]], 'official_score' => 80, 'reason' => 'Correction'])->assertStatus(409);
        $last = $this->submit($second, 'B');
        $this->act($last, 'override', ['reason' => 'PRIVATE Registrar reason']);
        Sanctum::actingAs($this->guest);
        $response = $this->getJson('/api/admission/result')->assertJsonPath('data.result.outcome', 'PASSED')->assertJsonPath('data.result.score', null);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->getJson('/api/admission/recommendation')->assertOk()->assertJsonMissingPath('data.recommendation.evidence');
        $this->act($last, 'correct', ['official_score' => 40, 'reason' => 'Verified correction']);
        $this->assertFalse($last->fresh()->registrar_pass);
        $this->assertSame('FAILED', $last->fresh()->outcome());
        $this->assertDatabaseHas('admission_decisions', ['action' => 'registrar_pass', 'internal_reason' => 'PRIVATE Registrar reason']);
    }

    public function test_configuration_questions_roles_and_safe_delete(): void
    {
        Sanctum::actingAs($this->admin);
        $body = ['question_code' => 'REAL-1', 'topic' => ProgramMatcher::INTEREST_CATEGORIES[0], 'question_text' => 'Question', 'option_a' => 'a', 'option_b' => 'b', 'option_c' => 'c', 'option_d' => 'd', 'correct_answer' => 'A', 'difficulty' => 'easy', 'status' => 'draft'];
        $q = $this->postJson('/api/admission/admin/questions', $body)->assertOk()->json('data');
        $this->putJson('/api/admission/admin/questions/'.$q['id'], $body + ['version' => 999])->assertStatus(409);
        $this->deleteJson('/api/admission/admin/questions/'.$q['id'], ['version' => 1])->assertOk();
        $this->bank();
        $session = $this->start();
        Sanctum::actingAs($this->admin);
        $question = AdmissionExamSession::find($session['session_id'])->questions()->first()->question_id;
        $this->deleteJson('/api/admission/admin/questions/'.$question, ['version' => 1])->assertStatus(409);
        foreach ([$this->guest, $this->registrar, $this->user(Role::PROFESSOR), $this->user(Role::STUDENT)] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/admission/admin/questions')->assertForbidden();
        }
    }

    public function test_cross_user_and_student_mutations_are_denied(): void
    {
        $this->bank();
        $session = $this->start();
        Sanctum::actingAs($this->user(Role::GUEST));
        $this->putJson('/api/admission/exam/'.$session['session_id'].'/answers', $this->answers($session))->assertNotFound();
        foreach ([Role::STUDENT, Role::PROFESSOR, Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/admission/exam/start')->assertForbidden();
            $this->getJson('/api/admission/registrar/results')->assertStatus($role === Role::REGISTRAR_STAFF ? 200 : 403);
        }
        Sanctum::actingAs($this->guest);
        User::whereKey($this->guest->id)->update(['status' => 'suspended']);
        $this->getJson('/api/admission/exam')->assertForbidden();
    }

    public function test_incomplete_bank_refuses_start_and_exam_audit_failure_rolls_back(): void
    {
        Sanctum::actingAs($this->guest);
        $this->getJson('/api/admission/exam')->assertJsonPath('data.reason', 'bank_incomplete');
        $this->postJson('/api/admission/exam/start')->assertStatus(409);
        $this->bank();
        $this->mock(AdmissionAuditWriter::class, fn ($m) => $m->shouldReceive('workflow')->andThrow(new \RuntimeException('private database error')));
        $this->postJson('/api/admission/exam/start')->assertStatus(503)->assertJsonMissing(['message' => 'private database error']);
        $this->assertDatabaseCount('admission_exam_sessions', 0);
        $this->assertDatabaseCount('admission_exam_answers', 0);
    }

    public function test_recommendation_uses_result_evidence_and_provider_fallback(): void
    {
        $department = Department::create(['department_code' => 'TEST', 'department_name' => 'Test']);
        $course = Course::create(['department_id' => $department->id, 'course_code' => 'BSIT', 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active']);
        app(AdmissionConfigurationService::class)->program($this->admin, $course->id, [
            'status' => 'active', 'is_recommendable' => true, 'program_type' => 'degree', 'subjects' => ['Programming'], 'career_paths' => ['Developer'], 'display_order' => 1,
            'recommendation_profile' => ['Digital Literacy' => .4, 'Logical Reasoning' => .3, 'General Mathematics' => .25, 'Reading Comprehension' => .05],
        ]);
        config(['admission.gemini.api_key' => null]);
        $this->bank();
        $result = $this->submit($this->start());
        $this->act($result, 'approve');
        $this->act($result, 'publish');
        Sanctum::actingAs($this->guest);
        $ratings = array_fill_keys(ProgramMatcher::INTEREST_CATEGORIES, 5);
        $response = $this->postJson('/api/admission/recommendation', ['interests' => $ratings])->assertOk()
            ->assertJsonPath('data.result_id', $result->id)->assertJsonPath('data.recommendation.ai_status', 'unavailable')
            ->assertJsonPath('data.recommendation.ranked_programs.0.course_code', 'BSIT')
            ->assertJsonPath('data.recommendation.evidence.Science.correct', 20);
        $this->assertEquals(100, $response->json('data.recommendation.ranked_programs.0.score'));
        $this->assertDatabaseCount('admission_recommendations', 1);
        $this->postJson('/api/admission/recommendation', ['interests' => $ratings])->assertOk();
        $this->assertDatabaseCount('admission_recommendations', 1);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_batch_operations_and_step_up_requirement(): void
    {
        $this->bank();
        $result = $this->submit($this->start());
        Sanctum::actingAs($this->registrar);
        $this->postJson('/api/admission/registrar/results/approve', ['results' => [['id' => $result->id, 'version' => 1], ['id' => 9999, 'version' => 1]]])->assertNotFound();
        $this->assertSame('pending', $result->fresh()->official_status);
        $this->act($result, 'approve');
        $this->act($result, 'publish');
        $this->postJson('/api/admission/registrar/results/correct', ['results' => [['id' => $result->id, 'version' => $result->fresh()->version]], 'official_score' => 70, 'reason' => 'Review'])->assertStatus(428);
        $this->getJson('/api/admission/registrar/applicants?search=Test')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/admission/registrar/results?latest=1')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/admission/registrar/history')->assertOk()->assertJsonCount(2, 'data.decisions.data');
    }
}

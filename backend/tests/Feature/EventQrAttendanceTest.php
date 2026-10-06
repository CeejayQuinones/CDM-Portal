<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAttendanceSession;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventQrAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:05:00');
        $this->fixture = $this->fixture();
    }

    public function test_admin_opens_session_student_is_forbidden_and_second_open_is_idempotent(): void
    {
        foreach ([$this->fixture['admin']] as $staff) {
            $event = $this->fixture['event'];
            Sanctum::actingAs($staff);
            $first = $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
            $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated()->assertJsonPath('data.id', $first);
        }
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertForbidden();
        $this->assertDatabaseCount('event_attendance_sessions', 1);
        $this->assertDatabaseCount('event_audit_events', 1);
    }

    public function test_close_session_invalidates_qr_and_blocks_scans(): void
    {
        $token = $this->openAndToken();
        $session = EventAttendanceSession::query()->where('event_id', $this->fixture['event']->id)->firstOrFail();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session/close', ['version' => $session->version])->assertOk()->assertJsonPath('data.status', 'closed');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => $token])->assertConflict()->assertJsonPath('code', 'SESSION_CLOSED');
        $this->assertDatabaseHas('event_attendance_scans', ['result' => 'session_closed']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.session_closed']);
    }

    public function test_attendance_cannot_open_outside_published_event_window(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $future = $this->event('Future Event', [['audience_type' => 'all_students']]);
        $future->forceFill(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)])->save();
        $this->postJson("/api/events/{$future->id}/attendance/session")->assertConflict();
        $draft = $this->event('Draft Event', [['audience_type' => 'all_students']]);
        $draft->forceFill(['status' => 'draft'])->save();
        $this->postJson("/api/events/{$draft->id}/attendance/session")->assertConflict();
    }

    public function test_valid_qr_records_student_and_repeat_is_idempotent(): void
    {
        $token = $this->openAndToken();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $endpoint = '/api/events/'.$this->fixture['event']->id.'/attendance/scan';
        $this->postJson($endpoint, ['token' => $token])->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED')->assertJsonPath('data.attendance.status', 'present');
        $this->postJson($endpoint, ['token' => $token])->assertOk()->assertJsonPath('code', 'ALREADY_RECORDED');
        $this->assertDatabaseCount('event_attendances', 1);
        $this->assertDatabaseHas('event_attendances', [
            'student_id' => $this->fixture['eligible']->id, 'course_id_at_attendance' => $this->fixture['course'],
            'year_level_at_attendance' => 1, 'section_id_at_attendance' => $this->fixture['section'],
        ]);
        $this->assertDatabaseHas('event_attendance_scans', ['result' => 'accepted']);
        $this->assertDatabaseHas('event_attendance_scans', ['result' => 'duplicate']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.recorded']);
    }

    public function test_expired_tampered_rotated_and_wrong_session_tokens_are_rejected(): void
    {
        $first = $this->openAndToken();
        Sanctum::actingAs($this->fixture['admin']);
        $second = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/token')->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $endpoint = '/api/events/'.$this->fixture['event']->id.'/attendance/scan';
        $this->postJson($endpoint, ['token' => $first])->assertUnprocessable()->assertJsonPath('code', 'INVALID_TOKEN');
        $this->postJson($endpoint, ['token' => $second.'tampered'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_TOKEN');

        Sanctum::actingAs($this->fixture['admin']);
        $expiring = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/token')->assertOk()->json('data.token');
        $this->travel(46)->seconds();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson($endpoint, ['token' => $expiring])->assertStatus(410)->assertJsonPath('code', 'EXPIRED_TOKEN');

        Carbon::setTestNow('2026-10-01 09:05:00');
        $other = $this->event('Other Event', [['audience_type' => 'all_students']]);
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$other->id}/attendance/session")->assertCreated();
        $wrong = $this->postJson("/api/events/{$other->id}/attendance/token")->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson($endpoint, ['token' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'WRONG_SESSION');
    }

    public function test_course_year_and_section_eligibility_use_authoritative_current_academic_data(): void
    {
        $cases = [
            ['course', [['audience_type' => 'course', 'course_id' => $this->fixture['course']]], $this->fixture['wrong_course']],
            ['year', [['audience_type' => 'year_level', 'year_level' => 1]], $this->fixture['wrong_year']],
            ['section', [['audience_type' => 'section', 'section_id' => $this->fixture['section']]], $this->fixture['wrong_section']],
        ];
        foreach ($cases as [$name, $audiences, $wrongStudent]) {
            $event = $this->event(ucfirst($name).' Event', $audiences);
            $token = $this->openAndToken($event);
            Sanctum::actingAs($wrongStudent->user);
            $this->postJson("/api/events/{$event->id}/attendance/scan", ['token' => $token])->assertForbidden()->assertJsonPath('code', 'NOT_ELIGIBLE');
            Sanctum::actingAs($this->fixture['eligible']->user);
            $this->postJson("/api/events/{$event->id}/attendance/scan", ['token' => $token])->assertCreated();
        }
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_late_rule_applies_after_fifteen_minute_grace_period(): void
    {
        Carbon::setTestNow('2026-10-01 09:16:00');
        $token = $this->openAndToken();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => $token])->assertCreated()->assertJsonPath('data.attendance.status', 'late');
    }

    public function test_platform_policy_is_enforced_for_scanning_and_management(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => 'invalid'])->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => 'invalid'])->assertUnprocessable();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => 'invalid'])->assertForbidden();
    }

    public function test_manual_creation_and_correction_are_authorized_reasoned_and_audited(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();
        $record = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', ['student_id' => $this->fixture['eligible']->id, 'status' => 'present', 'reason' => 'Camera was unavailable.'])->assertCreated()->json('data');
        $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->patchJson('/api/events/'.$this->fixture['event']->id.'/attendance/'.$record['id'], ['status' => 'excused', 'version' => $record['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/events/'.$this->fixture['event']->id.'/attendance/'.$record['id'], ['status' => 'excused', 'version' => $record['version'], 'reason' => 'Approved medical documentation.'])->assertOk()->assertJsonPath('data.status', 'excused')->assertJsonPath('data.source', 'manual');
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk()->assertJsonPath('data.summary.eligible', 2)->assertJsonPath('data.summary.not_checked_in', 1)->assertJsonPath('data.students.data.0.attendance.status', 'excused');
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.manual_created']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.corrected']);

        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', ['student_id' => $this->fixture['eligible']->id, 'status' => 'late', 'reason' => 'Unauthorized attempt.'])->assertForbidden();
    }

    public function test_coordinator_correction_requires_existing_step_up(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();
        $record = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', ['student_id' => $this->fixture['eligible']->id, 'status' => 'present', 'reason' => 'Manual fallback used.'])->assertCreated()->json('data');
        Sanctum::actingAs($this->fixture['admin']);
        $this->patchJson('/api/events/'.$this->fixture['event']->id.'/attendance/'.$record['id'], ['status' => 'late', 'version' => 1, 'reason' => 'Correcting verified arrival time.'])->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }

    public function test_registered_and_requested_moderators_use_only_their_mobile_qr_workflows(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['registeredModerator']->id,
            'responsibility' => EventRoleAssignment::REGISTERED_MODERATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['requestedModerator']->user_id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();

        Sanctum::actingAs($this->fixture['eligible']->user);
        $static = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'static'])
            ->assertOk()->assertJsonPath('data.mode', 'static')->assertJsonPath('data.expires_at', null)->json('data.token');
        $dynamic = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'dynamic'])
            ->assertOk()->assertJsonPath('data.mode', 'dynamic')->json('data.token');

        Sanctum::actingAs($this->fixture['registeredModerator']);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $dynamic, 'workflow' => 'dynamic'])->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $static, 'workflow' => 'static'])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');

        Sanctum::actingAs($this->fixture['requestedTarget']->user);
        $requestedDynamic = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'dynamic'])
            ->assertOk()->json('data.token');
        $requestedStatic = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'static'])
            ->assertOk()->json('data.token');

        Sanctum::actingAs($this->fixture['requestedModerator']->user);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedStatic, 'workflow' => 'static'])->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedDynamic, 'workflow' => 'dynamic'])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.operator_qr_recorded']);

        Sanctum::actingAs($this->fixture['admin']);
        $assignment = EventRoleAssignment::query()->where('event_id', $this->fixture['event']->id)->where('user_id', $this->fixture['requestedModerator']->user_id)->firstOrFail();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/personnel/'.$assignment->id.'/revoke')->assertOk();
        Sanctum::actingAs($this->fixture['requestedModerator']->user);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedDynamic, 'workflow' => 'dynamic'])->assertForbidden();
    }

    public function test_promotional_api_is_public_safe_and_contains_no_operational_data(): void
    {
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-promotions')->assertUnauthorized();
        Sanctum::actingAs($this->user(Role::GUEST));
        $response = $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-promotions')->assertOk()
            ->assertJsonPath('data.data.0.title', 'General Assembly')
            ->assertJsonMissingPath('data.data.0.audiences')
            ->assertJsonMissingPath('data.data.0.capabilities')
            ->assertJsonMissingPath('data.data.0.attendance')
            ->assertJsonMissingPath('data.data.0.role_assignments')
            ->assertJsonMissingPath('data.data.0.qr_token');
        $this->assertSame([
            'id', 'title', 'description', 'venue', 'starts_at', 'ends_at', 'status', 'organizer', 'public_audience', 'image_url',
        ], array_keys($response->json('data.data.0')));
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/event-promotions')->assertForbidden();
    }

    private function openAndToken(?Event $event = null): string
    {
        $event ??= $this->fixture['event'];
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();

        return $this->postJson("/api/events/{$event->id}/attendance/token")->assertOk()->assertJsonPath('data.refresh_after_seconds', 30)->json('data.token');
    }

    private function fixture(): array
    {
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $admin = $this->user(Role::ADMIN);
        $department = DB::table('departments')->insertGetId(['department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = $this->course($department, 'BSIT');
        $otherCourse = $this->course($department, 'BSCS');
        $year = DB::table('academic_years')->insertGetId(['school_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $semester = DB::table('semesters')->insertGetId(['semester_name' => 'First Semester', 'semester_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $section = $this->section($course, $year, $semester, 'BSIT-1A', 1);
        $otherSection = $this->section($course, $year, $semester, 'BSIT-1B', 1);
        $yearTwoSection = $this->section($course, $year, $semester, 'BSIT-2A', 2);
        $otherCourseSection = $this->section($otherCourse, $year, $semester, 'BSCS-1A', 1);
        $eligible = $this->student($course, $section, $year, $semester, '0001');
        $requestedTarget = $this->student($course, $section, $year, $semester, '0005');
        $requestedModerator = $this->student($course, $otherSection, $year, $semester, '0006');
        $registeredModerator = $this->user(Role::PROFESSOR);
        $wrongSection = $this->student($course, $otherSection, $year, $semester, '0002');
        $wrongYear = $this->student($course, $yearTwoSection, $year, $semester, '0003');
        $wrongCourse = $this->student($otherCourse, $otherCourseSection, $year, $semester, '0004');
        $event = $this->event('General Assembly', [['audience_type' => 'section', 'section_id' => $section]]);

        return ['registrar' => $registrar, 'admin' => $admin, 'course' => $course, 'section' => $section, 'eligible' => $eligible, 'requestedTarget' => $requestedTarget, 'requestedModerator' => $requestedModerator, 'registeredModerator' => $registeredModerator, 'wrong_section' => $wrongSection, 'wrong_year' => $wrongYear, 'wrong_course' => $wrongCourse, 'event' => $event];
    }

    private function user(string $role): User
    {
        $roleModel = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::query()->create(['username' => 'event_user_'.(++$this->sequence), 'password' => Hash::make('password'), 'role_id' => $roleModel->id, 'status' => 'active', 'is_first_login' => false]);
    }

    private function course(int $department, string $code): int
    {
        return DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => $code, 'course_name' => $code, 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function section(int $course, int $year, int $semester, string $name, int $level): int
    {
        return DB::table('sections')->insertGetId(['course_id' => $course, 'academic_year_id' => $year, 'semester_id' => $semester, 'section_name' => $name, 'year_level' => $level, 'capacity' => 40, 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function student(int $course, int $section, int $year, int $semester, string $number): Student
    {
        $user = $this->user(Role::STUDENT);
        $profile = DB::table('user_profiles')->insertGetId(['user_id' => $user->id, 'first_name' => 'Student', 'last_name' => $number, 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'CUR-'.$number, 'curriculum_name' => 'Curriculum '.$number, 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $student = Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => '2026-'.$number, 'admission_date' => '2026-08-01', 'year_level' => DB::table('sections')->where('id', $section)->value('year_level'), 'student_status' => 'regular']);
        DB::table('enrollments')->insert(['student_id' => $student->id, 'section_id' => $section, 'academic_year_id' => $year, 'semester_id' => $semester, 'enrollment_date' => '2026-08-01', 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]);

        return $student;
    }

    private function event(string $title, array $audiences): Event
    {
        $adminId = User::query()->whereHas('role', fn ($query) => $query->where('role_name', Role::ADMIN))->value('id');
        $event = Event::query()->create(['title' => $title, 'venue' => $title.' Hall', 'venue_key' => strtolower($title).' hall', 'starts_at' => '2026-10-01 09:00:00', 'ends_at' => '2026-10-01 11:00:00', 'status' => 'published', 'created_by' => $adminId, 'updated_by' => $adminId]);
        $event->audiences()->createMany($audiences);

        return $event;
    }
}

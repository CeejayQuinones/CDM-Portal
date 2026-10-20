<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventAttendanceSession;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventModeratorRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $studentUser;

    private Student $student;

    private Event $event;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');
        $this->admin = $this->user(Role::ADMIN, 'Event Coordinator');
        $this->studentUser = $this->user(Role::STUDENT, 'Student Participant');
        $department = DB::table('departments')->insertGetId(['department_code' => 'EVR', 'department_name' => 'Event Requests', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => 'EVR', 'course_name' => 'Event Requests', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'EVR-2026', 'curriculum_name' => 'Event Requests', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->student = Student::query()->create([
            'user_id' => $this->studentUser->id, 'user_profile_id' => $this->studentUser->profile->id,
            'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => 'EVR-001',
            'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular',
        ]);
        $this->event = Event::query()->create([
            'title' => 'Moderator Workflow Event', 'venue' => 'Campus Hall', 'venue_key' => 'campus hall',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'status' => 'published',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        $this->event->audiences()->create(['audience_type' => 'all_students']);
    }

    public function test_pending_request_is_unique_has_no_privileges_and_approval_is_event_scoped(): void
    {
        Sanctum::actingAs($this->studentUser);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/profile')
            ->assertOk()
            ->assertJsonPath('data.identity.event_responsibility', 'participant')
            ->assertJsonPath('data.available_events.0.can_request_moderator', true);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->event->id)
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_request_moderator', true)
            ->assertJsonPath('data.capabilities.can_manage_event', false)
            ->assertJsonPath('data.capabilities.can_operate_attendance', false);
        $request = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests', ['reason' => 'I am helping with attendance verification.'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.request_reason', 'I am helping with attendance verification.')->json('data');
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests')->assertUnprocessable();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->event->id.'/attendance')->assertForbidden();
        $this->assertDatabaseMissing('event_role_assignments', ['event_id' => $this->event->id, 'user_id' => $this->studentUser->id]);
        $this->assertDatabaseHas('event_audit_events', ['event_id' => $this->event->id, 'action' => 'event.moderator.requested']);

        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/profile')
            ->assertOk()
            ->assertJsonPath('data.identity.event_responsibility', EventRoleAssignment::REQUESTED_MODERATOR)
            ->assertJsonPath('data.available_events.0.can_request_moderator', false)
            ->assertJsonPath('data.available_events.0.moderator_request.request_reason', 'I am helping with attendance verification.');

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->event->id.'/moderator-requests')
            ->assertOk()->assertJsonPath('data.0.email', 'student.participant@example.test');
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->event->id.'/moderator-requests/'.$request['id'].'/approve')
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('event_role_assignments', [
            'event_id' => $this->event->id,
            'user_id' => $this->studentUser->id,
            'responsibility' => EventRoleAssignment::REGISTERED_MODERATOR,
            'revoked_at' => null,
        ]);
        $this->assertSame(Role::STUDENT, $this->studentUser->fresh()->role->role_name);

        Sanctum::actingAs($this->studentUser);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->event->id.'/attendance')->assertOk();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->event->id)
            ->assertOk()
            ->assertJsonPath('data.capabilities.event_responsibility', EventRoleAssignment::REGISTERED_MODERATOR)
            ->assertJsonPath('data.capabilities.can_scan_static_participant_qr', true)
            ->assertJsonPath('data.capabilities.can_scan_dynamic_participant_qr', false);

        $this->event->forceFill(['attendance_methods' => ['manual']])->save();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->event->id)
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_operate_attendance', true)
            ->assertJsonPath('data.capabilities.can_scan_static_participant_qr', false);
    }

    public function test_rejection_and_revocation_keep_history_and_notify_requester(): void
    {
        Sanctum::actingAs($this->studentUser);
        $first = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests')->assertCreated()->json('data');

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->event->id.'/moderator-requests/'.$first['id'].'/reject', ['reason' => 'Additional Event staffing is not required.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        Sanctum::actingAs($this->studentUser);
        $second = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests')->assertCreated()->json('data');
        Sanctum::actingAs($this->admin);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->event->id.'/moderator-requests/'.$second['id'].'/approve')->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->event->id.'/moderator-requests/'.$second['id'].'/revoke', ['reason' => 'The Event staffing period has ended.'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseCount('event_moderator_requests', 2);
        $this->assertDatabaseHas('event_moderator_requests', ['id' => $first['id'], 'status' => 'rejected']);
        $this->assertDatabaseHas('event_moderator_requests', ['id' => $second['id'], 'status' => 'revoked']);
        $this->assertNotNull(EventRoleAssignment::query()->where('event_id', $this->event->id)->where('user_id', $this->studentUser->id)->value('revoked_at'));
        $this->assertDatabaseHas('event_audit_events', ['action' => 'event.moderator.rejected']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'event.moderator.revoked']);

        Sanctum::actingAs($this->studentUser);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/notifications')
            ->assertOk()->assertJsonPath('unread_count', 3);
    }

    public function test_only_coordinator_reviews_requests_and_participant_profile_has_attendance_history(): void
    {
        Sanctum::actingAs($this->studentUser);
        $moderatorRequest = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests')->assertCreated()->json('data');
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests/'.$moderatorRequest['id'].'/approve')->assertForbidden();

        $professor = $this->user(Role::PROFESSOR, 'Semi Coordinator');
        EventRoleAssignment::query()->create([
            'event_id' => $this->event->id, 'user_id' => $professor->id, 'responsibility' => EventRoleAssignment::SEMI_COORDINATOR,
            'assigned_by_user_id' => $this->admin->id, 'assigned_at' => now(),
        ]);
        Sanctum::actingAs($professor);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->event->id.'/moderator-requests')->assertOk();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->event->id.'/moderator-requests/'.$moderatorRequest['id'].'/approve')->assertForbidden();

        $session = EventAttendanceSession::query()->create(['event_id' => $this->event->id, 'opened_by' => $this->admin->id, 'opened_at' => now(), 'status' => 'open']);
        EventAttendance::query()->create([
            'event_id' => $this->event->id, 'attendance_session_id' => $session->id, 'student_id' => $this->student->id,
            'status' => 'present', 'checked_in_at' => now(), 'source' => 'manual', 'recorded_by' => $this->admin->id,
        ]);
        Sanctum::actingAs($this->studentUser);
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/profile')->assertOk()
            ->assertJsonPath('data.identity.student_number', 'EVR-001')
            ->assertJsonPath('data.available_events.0.title', 'Moderator Workflow Event')
            ->assertJsonPath('data.moderator_requests.0.status', 'pending')
            ->assertJsonPath('data.attended_events.data.0.title', 'Moderator Workflow Event')
            ->assertJsonPath('data.attended_events.data.0.method', 'manual');
    }

    public function test_coordinator_and_operational_responsibilities_cannot_request_moderator_access(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/profile')
            ->assertOk()
            ->assertJsonPath('data.identity.event_responsibility', 'coordinator')
            ->assertJsonPath('data.available_events.0.can_request_moderator', false);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->event->id.'/moderator-requests')->assertForbidden();

        foreach ([EventRoleAssignment::SEMI_COORDINATOR, EventRoleAssignment::REGISTERED_MODERATOR, EventRoleAssignment::EVENT_STAFF] as $responsibility) {
            $professor = $this->user(Role::PROFESSOR, 'Operational '.str_replace('_', ' ', $responsibility));
            EventRoleAssignment::query()->create([
                'event_id' => $this->event->id,
                'user_id' => $professor->id,
                'responsibility' => $responsibility,
                'assigned_by_user_id' => $this->admin->id,
                'assigned_at' => now(),
            ]);

            $client = 'mobile';
            Sanctum::actingAs($professor);
            $this->withHeader('X-CDM-Client', $client)->getJson('/api/events/'.$this->event->id)
                ->assertOk()
                ->assertJsonPath('data.capabilities.can_request_moderator', false)
                ->assertJsonPath('data.capabilities.can_manage_people', in_array($responsibility, [EventRoleAssignment::SEMI_COORDINATOR], true))
                ->assertJsonPath('data.capabilities.can_scan_attendance', true);
            $this->withHeader('X-CDM-Client', $client)->postJson('/api/events/'.$this->event->id.'/moderator-requests')->assertForbidden();
            $this->assertSame(Role::PROFESSOR, $professor->fresh()->role->role_name);
        }
    }

    private function user(string $role, string $name): User
    {
        $roleModel = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);
        $slug = strtolower(str_replace(' ', '.', $name));
        $user = User::query()->create(['username' => 'event_request_'.(++$this->sequence), 'password' => 'password', 'role_id' => $roleModel->id, 'status' => 'active', 'is_first_login' => false]);
        $user->profile()->create(['first_name' => strtok($name, ' '), 'last_name' => substr($name, strrpos($name, ' ') + 1), 'email' => $slug.'@example.test', 'gender' => 'Prefer not to say']);

        return $user->fresh(['profile', 'role']);
    }
}

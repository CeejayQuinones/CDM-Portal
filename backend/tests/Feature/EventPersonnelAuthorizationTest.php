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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventPersonnelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-04 10:00:00');
        $this->fixture = $this->fixture();
    }

    public function test_coordinator_assigns_changes_and_idempotently_revokes_event_responsibilities(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $assigned = $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated()
            ->assertJsonPath('data.portal_role', Role::STUDENT)
            ->assertJsonPath('data.responsibility', EventRoleAssignment::REQUESTED_MODERATOR)
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        $this->assertSame(Role::STUDENT, $this->fixture['classMayor']->fresh()->role->role_name);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated();
        $this->assertDatabaseCount('event_role_assignments', 1);
        $this->assertSame(1, DB::table('event_audit_events')->where('action', 'event.personnel.assigned')->count());
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/personnel?search=Mayor')->assertOk()
            ->assertJsonPath('data.candidates.0.name', 'Mayor User')
            ->assertJsonPath('data.candidates.0.portal_role', Role::STUDENT)
            ->assertJsonMissingPath('data.candidates.0.username')
            ->assertJsonMissingPath('data.candidates.0.email');

        $this->putJson('/api/events/'.$this->fixture['event']->id.'/personnel/'.$assigned['id'], [
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertOk()->assertJsonPath('data.responsibility', EventRoleAssignment::EVENT_STAFF);
        $this->assertDatabaseHas('event_audit_events', [
            'event_id' => $this->fixture['event']->id,
            'action' => 'event.personnel.role_changed',
        ]);

        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel/'.$assigned['id'].'/revoke')
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel/'.$assigned['id'].'/revoke')
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame(1, DB::table('event_audit_events')->where('action', 'event.personnel.revoked')->count());
    }

    public function test_assignment_eligibility_and_personnel_management_are_enforced(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['professor']->id,
            'responsibility' => EventRoleAssignment::CLASS_MAYOR,
        ])->assertUnprocessable();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['eventStaff']->id,
            'responsibility' => EventRoleAssignment::SEMI_COORDINATOR,
        ])->assertUnprocessable();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['guest']->id,
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertUnprocessable();
        $this->fixture['eventStaff']->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['eventStaff']->id,
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertUnprocessable();

        foreach ([$this->fixture['classMayor'], $this->fixture['professor'], $this->fixture['guest']] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/events/'.$this->fixture['event']->id.'/personnel')->assertForbidden();
            $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
                'user_id' => $this->fixture['eventStaff']->id,
                'responsibility' => EventRoleAssignment::EVENT_STAFF,
            ])->assertForbidden();
        }
    }

    public function test_semi_coordinator_has_desktop_only_scoped_management_without_coordinator_authority(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $assignment = $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['registrar']->id,
            'responsibility' => EventRoleAssignment::SEMI_COORDINATOR,
        ])->assertCreated()->assertJsonPath('data.responsibility', EventRoleAssignment::SEMI_COORDINATOR)->json('data');

        Sanctum::actingAs($this->fixture['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['event']->id)
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_edit_event', true)
            ->assertJsonPath('data.capabilities.can_manage_personnel', false)
            ->assertJsonPath('data.capabilities.can_manage_attendance_session', true)
            ->assertJsonPath('data.capabilities.can_view_reports', true)
            ->assertJsonPath('data.capabilities.can_export_reports', false);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id)->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->fixture['event']->id)->assertForbidden();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['unrelatedEvent']->id)->assertNotFound();
        $this->putJson('/api/events/'.$this->fixture['event']->id, $this->eventPayload('Semi Updated'))->assertOk();
        $this->postJson('/api/events', array_merge($this->eventPayload('Semi Child'), ['parent_event_id' => $this->fixture['event']->id, 'audiences' => []]))
            ->assertCreated()->assertJsonPath('data.parent_event_id', $this->fixture['event']->id);
        $this->postJson('/api/events', $this->eventPayload('Forbidden Top Level'))->assertForbidden();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/cancel', ['version' => 2])->assertForbidden();
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/personnel')->assertOk();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['eventStaff']->id,
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertForbidden();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/token')->assertOk();
        $this->getJson('/api/event-reports/'.$this->fixture['event']->id)
            ->assertOk()->assertJsonPath('data.capabilities.can_export', false);
        $this->get('/api/event-reports/'.$this->fixture['event']->id.'/export?format=csv')->assertForbidden();

        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel/'.$assignment['id'].'/revoke')->assertOk();
        Sanctum::actingAs($this->fixture['registrar']);
        $this->getJson('/api/events/'.$this->fixture['event']->id)->assertForbidden();
    }

    public function test_moderator_event_staff_and_class_mayor_receive_only_scoped_operational_access(): void
    {
        $assignments = [
            [$this->fixture['professor'], EventRoleAssignment::REGISTERED_MODERATOR, $this->fixture['event']],
            [$this->fixture['eventStaff'], EventRoleAssignment::EVENT_STAFF, $this->fixture['secondEvent']],
            [$this->fixture['classMayor'], EventRoleAssignment::REQUESTED_MODERATOR, $this->fixture['thirdEvent']],
        ];

        Sanctum::actingAs($this->fixture['admin']);
        foreach ($assignments as [$user, $responsibility, $event]) {
            $this->postJson('/api/events/'.$event->id.'/personnel', [
                'user_id' => $user->id,
                'responsibility' => $responsibility,
            ])->assertCreated();
        }

        foreach ($assignments as [$user, $responsibility, $event]) {
            Sanctum::actingAs($user);
            $this->getJson('/api/events/'.$event->id)->assertOk()
                ->assertJsonPath('data.capabilities.event_responsibility', $responsibility)
                ->assertJsonPath('data.capabilities.can_operate_attendance', true)
                ->assertJsonPath('data.capabilities.can_manage_event', false)
                ->assertJsonPath('data.capabilities.can_view_reports', false);
            $this->getJson('/api/events/'.$event->id.'/attendance')->assertOk();
            $this->postJson('/api/events/'.$event->id.'/attendance/manual', [
                'student_id' => $this->fixture['attendee']->id,
                'status' => 'present',
                'reason' => 'Verified at the Event entrance.',
            ])->assertCreated();
            $this->postJson('/api/events/'.$event->id.'/attendance/session')->assertForbidden();
            $this->postJson('/api/events/'.$event->id.'/attendance/token')->assertForbidden();
            $this->getJson('/api/event-reports/'.$event->id)->assertForbidden();
            $this->putJson('/api/events/'.$event->id, $this->eventPayload('Unauthorized update'))->assertForbidden();
            $foreignEvent = $event->id === $this->fixture['unrelatedEvent']->id ? $this->fixture['event'] : $this->fixture['unrelatedEvent'];
            $this->getJson('/api/events/'.$foreignEvent->id.'/attendance')->assertForbidden();
        }

        Sanctum::actingAs($this->fixture['classMayor']);
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
    }

    public function test_normal_student_cannot_gain_operations_by_changing_event_id_and_revoke_removes_access(): void
    {
        Sanctum::actingAs($this->fixture['normalStudent']);
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', [
            'student_id' => $this->fixture['attendee']->id,
            'status' => 'present',
            'reason' => 'Unauthorized check-in attempt.',
        ])->assertForbidden();

        Sanctum::actingAs($this->fixture['admin']);
        $assignment = $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated()->json('data');
        Sanctum::actingAs($this->fixture['classMayor']);
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk();
        $this->getJson('/api/events/'.$this->fixture['secondEvent']->id.'/attendance')->assertForbidden();

        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel/'.$assignment['id'].'/revoke')->assertOk();
        Sanctum::actingAs($this->fixture['classMayor']);
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
    }

    public function test_parent_assignment_inherits_to_sub_event_but_direct_child_assignment_does_not_grant_parent(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['childEvent']->id.'/personnel', [
            'user_id' => $this->fixture['eventStaff']->id,
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertCreated();

        Sanctum::actingAs($this->fixture['classMayor']);
        $this->getJson('/api/events/'.$this->fixture['childEvent']->id.'/attendance')->assertOk();
        $this->getJson('/api/events/'.$this->fixture['unrelatedEvent']->id.'/attendance')->assertForbidden();
        $this->getJson('/api/events/'.$this->fixture['childEvent']->id)->assertOk()
            ->assertJsonPath('data.capabilities.assignment_inherited', true)
            ->assertJsonPath('data.capabilities.assignment_source_event_id', $this->fixture['event']->id);

        Sanctum::actingAs($this->fixture['eventStaff']);
        $this->getJson('/api/events/'.$this->fixture['childEvent']->id.'/attendance')->assertOk();
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
    }

    public function test_assignment_expiry_and_event_completion_remove_operational_authority(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addMinute()->toIso8601String(),
        ])->assertCreated();

        Sanctum::actingAs($this->fixture['classMayor']);
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();

        Carbon::setTestNow('2026-10-04 13:00:00');
        Sanctum::actingAs($this->fixture['eventStaff']);
        EventRoleAssignment::query()->updateOrCreate(
            ['event_id' => $this->fixture['event']->id, 'user_id' => $this->fixture['eventStaff']->id],
            ['responsibility' => EventRoleAssignment::EVENT_STAFF, 'assigned_by_user_id' => $this->fixture['admin']->id, 'assigned_at' => now()],
        );
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
    }

    public function test_platform_policy_still_applies_to_assigned_personnel_and_coordinators(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['registrar']->id,
            'responsibility' => EventRoleAssignment::SEMI_COORDINATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['professor']->id,
            'responsibility' => EventRoleAssignment::REGISTERED_MODERATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['secondEvent']->id.'/personnel', [
            'user_id' => $this->fixture['eventStaff']->id,
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertCreated();

        Sanctum::actingAs($this->fixture['classMayor']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk();
        Sanctum::actingAs($this->fixture['eventStaff']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['secondEvent']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['secondEvent']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->fixture['secondEvent']->id.'/attendance')->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        Sanctum::actingAs($this->fixture['professor']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk();
        Sanctum::actingAs($this->fixture['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk();
    }

    public function test_class_mayor_keeps_normal_student_self_check_in_for_an_eligible_assigned_event(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['classMayor']->id,
            'responsibility' => EventRoleAssignment::REQUESTED_MODERATOR,
        ])->assertCreated();
        $token = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/token')->assertOk()->json('data.token');

        Sanctum::actingAs($this->fixture['classMayor']);
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => $token])
            ->assertCreated()
            ->assertJsonPath('code', 'ATTENDANCE_RECORDED');
        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $this->fixture['event']->id,
            'student_id' => $this->fixture['classMayor']->student->id,
            'recorded_by' => $this->fixture['classMayor']->id,
        ]);
        $this->assertSame(Role::STUDENT, $this->fixture['classMayor']->fresh()->role->role_name);
    }

    private function fixture(): array
    {
        $admin = $this->user(Role::ADMIN, 'Admin');
        $registrar = $this->user(Role::PROFESSOR, 'Semi Coordinator');
        $professor = $this->user(Role::PROFESSOR, 'Professor');
        $classMayor = $this->user(Role::STUDENT, 'Mayor');
        $eventStaff = $this->user(Role::STUDENT, 'Staff');
        $normalStudent = $this->user(Role::STUDENT, 'Normal');
        $guest = $this->user(Role::GUEST, 'Guest');
        $department = DB::table('departments')->insertGetId(['department_code' => 'EVT', 'department_name' => 'Events', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => 'BSEV', 'course_name' => 'Events', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'EVT-2026', 'curriculum_name' => 'Events 2026', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $attendee = $this->studentIdentity($normalStudent, $course, $curriculum, '2026-EVT-01');
        $this->studentIdentity($classMayor, $course, $curriculum, '2026-EVT-02');
        $this->studentIdentity($eventStaff, $course, $curriculum, '2026-EVT-03');
        $event = $this->event($admin, 'Main Event');
        $secondEvent = $this->event($admin, 'Second Event');
        $thirdEvent = $this->event($admin, 'Third Event');
        $unrelatedEvent = $this->event($admin, 'Unrelated Event');
        $childEvent = $this->event($admin, 'Child Event', $event->id);
        foreach ([$event, $secondEvent, $thirdEvent, $unrelatedEvent, $childEvent] as $item) {
            EventAttendanceSession::query()->create(['event_id' => $item->id, 'opened_by' => $admin->id, 'opened_at' => now(), 'status' => 'open']);
        }

        return compact('admin', 'registrar', 'professor', 'classMayor', 'eventStaff', 'normalStudent', 'guest', 'attendee', 'event', 'secondEvent', 'thirdEvent', 'unrelatedEvent', 'childEvent');
    }

    private function user(string $role, string $name): User
    {
        $roleModel = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);
        $user = User::query()->create(['username' => 'personnel_'.(++$this->sequence), 'password' => 'password', 'role_id' => $roleModel->id, 'status' => 'active', 'is_first_login' => false]);
        $user->profile()->create(['first_name' => $name, 'last_name' => 'User', 'gender' => 'Prefer not to say']);

        return $user;
    }

    private function studentIdentity(User $user, int $course, int $curriculum, string $number): Student
    {
        return Student::query()->create([
            'user_id' => $user->id, 'user_profile_id' => $user->profile->id, 'course_id' => $course, 'curriculum_id' => $curriculum,
            'student_number' => $number, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular',
        ]);
    }

    private function event(User $admin, string $title, ?int $parentId = null): Event
    {
        $event = Event::query()->create([
            'parent_event_id' => $parentId, 'title' => $title, 'venue' => 'Campus', 'venue_key' => strtolower($title),
            'starts_at' => '2026-10-04 09:00:00', 'ends_at' => '2026-10-04 12:00:00', 'status' => 'published',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        if ($parentId === null) {
            $event->audiences()->create(['audience_type' => 'all_students']);
        }

        return $event;
    }

    private function eventPayload(string $title): array
    {
        return ['title' => $title, 'venue' => 'Campus', 'starts_at' => '2026-10-04 09:00:00', 'ends_at' => '2026-10-04 12:00:00', 'audiences' => [['audience_type' => 'all_students']], 'version' => 1];
    }
}

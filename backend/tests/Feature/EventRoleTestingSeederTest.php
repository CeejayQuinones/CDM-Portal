<?php

namespace Tests\Feature;

use Database\Seeders\EventRoleTestingSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class EventRoleTestingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_event_personas_are_small_deterministic_and_idempotent(): void
    {
        $this->seed(RolesSeeder::class);
        $department = DB::table('departments')->insertGetId([
            'department_code' => 'EVT', 'department_name' => 'Event Testing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $course = DB::table('courses')->insertGetId([
            'department_id' => $department, 'course_code' => 'EVT', 'course_name' => 'Event Testing', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('curriculums')->insert([
            'course_id' => $course, 'curriculum_code' => 'EVT-TEST', 'curriculum_name' => 'Event Testing', 'effective_year' => 2026,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seed(EventRoleTestingSeeder::class);
        $this->seed(EventRoleTestingSeeder::class);

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_role_assignments', 4);
        $this->assertSame(7, DB::table('users')->count());
        $this->assertSame(2, DB::table('students')->count());
        $this->assertSame(4, DB::table('professors')->count());
        $this->assertSame(0, DB::table('registrar_staff')->count());
        $this->assertDatabaseHas('event_role_assignments', ['responsibility' => 'semi_coordinator']);
        $this->assertDatabaseHas('events', ['title' => 'IT Validation Event', 'status' => 'published']);

        $expected = [
            'event_coordinator' => ['Admin', 'Admin123!'],
            'event_semicoordinator' => ['Professor', 'Professor123!'],
            'event_registered_moderator' => ['Professor', 'Professor123!'],
            'event_staff' => ['Professor', 'Professor123!'],
            'event_professor' => ['Professor', 'Professor123!'],
            'event_requested_moderator' => ['Student', 'Student123!'],
            'event_student' => ['Student', 'Student123!'],
        ];
        foreach ($expected as $username => [$role, $password]) {
            $user = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('username', $username)->first();
            $this->assertNotNull($user);
            $this->assertSame($role, $user->role_name);
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertSame('active', $user->status);
            $this->assertSame(1, (int) $user->is_first_login);
        }

        $assignedUsernames = DB::table('event_role_assignments')
            ->join('users', 'users.id', '=', 'event_role_assignments.user_id')
            ->orderBy('users.username')->pluck('users.username')->all();
        $this->assertSame(['event_registered_moderator', 'event_requested_moderator', 'event_semicoordinator', 'event_staff'], $assignedUsernames);
        $this->assertDatabaseHas('students', ['student_number' => 'EVT-26-001']);
        $this->assertDatabaseHas('students', ['student_number' => 'EVT-26-002']);
        $this->assertDatabaseHas('professors', ['employee_number' => 'EVT-FAC-001']);
        $this->assertDatabaseHas('professors', ['employee_number' => 'EVT-FAC-002']);
        $this->assertDatabaseHas('professors', ['employee_number' => 'EVT-FAC-003']);
        $this->assertDatabaseHas('professors', ['employee_number' => 'EVT-FAC-004']);
    }

    public function test_event_persona_seeder_refuses_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        $this->expectException(LogicException::class);
        $this->seed(EventRoleTestingSeeder::class);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Event;
use App\Models\EventAudience;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Event\EventPersonnelService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LogicException;

class EventRoleTestingSeeder extends Seeder
{
    private const ACCOUNTS = [
        'coordinator' => [
            'username' => 'event_coordinator', 'password' => 'Admin123!', 'role' => Role::ADMIN,
            'first_name' => 'Event', 'last_name' => 'Coordinator', 'email' => 'event_coordinator@cdm.edu.ph',
        ],
        'semi_coordinator' => [
            'username' => 'event_semicoordinator', 'password' => 'Professor123!', 'role' => Role::PROFESSOR,
            'first_name' => 'Event', 'last_name' => 'Semi-Coordinator', 'email' => 'event_semicoordinator@cdm.edu.ph',
        ],
        'registered_moderator' => [
            'username' => 'event_registered_moderator', 'password' => 'Professor123!', 'role' => Role::PROFESSOR,
            'first_name' => 'Event', 'last_name' => 'Registered Moderator', 'email' => 'event_registered_moderator@cdm.edu.ph',
        ],
        'staff' => [
            'username' => 'event_staff', 'password' => 'Professor123!', 'role' => Role::PROFESSOR,
            'first_name' => 'Event', 'last_name' => 'Staff', 'email' => 'event_staff@cdm.edu.ph',
        ],
        'professor' => [
            'username' => 'event_professor', 'password' => 'Professor123!', 'role' => Role::PROFESSOR,
            'first_name' => 'Event', 'last_name' => 'Professor', 'email' => 'event_professor@cdm.edu.ph',
        ],
        'requested_moderator' => [
            'username' => 'event_requested_moderator', 'password' => 'Student123!', 'role' => Role::STUDENT,
            'first_name' => 'Event', 'last_name' => 'Requested Moderator', 'email' => 'event_requested_moderator@cdm.edu.ph',
        ],
        'student' => [
            'username' => 'event_student', 'password' => 'Student123!', 'role' => Role::STUDENT,
            'first_name' => 'Event', 'last_name' => 'Student', 'email' => 'event_student@cdm.edu.ph',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('EventRoleTestingSeeder is disabled in production.');
        }
        if (! Schema::hasTable('event_role_assignments')) {
            throw new LogicException('Run the Event role assignment migration before this local development seeder.');
        }

        DB::transaction(function (): void {
            $this->migrateLegacyFixtureUsernames();
            $accounts = collect(self::ACCOUNTS)->mapWithKeys(fn (array $definition, string $persona) => [
                $persona => $this->seedAccount($definition),
            ]);

            DB::table('registrar_staff')->where('employee_number', 'EVT-REG-001')->delete();
            $this->seedProfessorIdentity($accounts['registered_moderator'], 'EVT-FAC-001');
            $this->seedProfessorIdentity($accounts['staff'], 'EVT-FAC-002');
            $this->seedProfessorIdentity($accounts['professor'], 'EVT-FAC-003');
            $this->seedProfessorIdentity($accounts['semi_coordinator'], 'EVT-FAC-004');
            $this->seedStudentIdentity($accounts['requested_moderator'], 'EVT-26-001');
            $this->seedStudentIdentity($accounts['student'], 'EVT-26-002');

            $event = Event::query()->updateOrCreate(
                ['title' => 'IT Validation Event'],
                [
                    'parent_event_id' => null,
                    'description' => 'Local-development Event responsibility and platform validation fixture.',
                    'venue' => 'IT Validation Room',
                    'venue_key' => 'it validation room',
                    'starts_at' => now()->subHour(),
                    'ends_at' => now()->addDays(30),
                    'status' => 'published',
                    'created_by' => $accounts['coordinator']->id,
                    'updated_by' => $accounts['coordinator']->id,
                ],
            );
            EventAudience::query()->updateOrCreate(
                ['event_id' => $event->id, 'audience_type' => 'all_users'],
                ['course_id' => null, 'year_level' => null, 'section_id' => null],
            );

            $assignedUserIds = $accounts->only(['semi_coordinator', 'registered_moderator', 'staff', 'requested_moderator'])->pluck('id');
            EventRoleAssignment::query()->where('event_id', $event->id)->whereNotIn('user_id', $assignedUserIds)->delete();

            $personnel = app(EventPersonnelService::class);
            $personnel->assign($event, $accounts['semi_coordinator'], EventRoleAssignment::SEMI_COORDINATOR, $accounts['coordinator']);
            $personnel->assign($event, $accounts['registered_moderator'], EventRoleAssignment::REGISTERED_MODERATOR, $accounts['coordinator']);
            $personnel->assign($event, $accounts['staff'], EventRoleAssignment::EVENT_STAFF, $accounts['coordinator']);
            $personnel->assign($event, $accounts['requested_moderator'], EventRoleAssignment::REQUESTED_MODERATOR, $accounts['coordinator']);

            $this->printSummary($event, $accounts->all());
        }, 3);
    }

    /** @param array<string, string> $definition */
    private function seedAccount(array $definition): User
    {
        $roleId = Role::query()->where('role_name', $definition['role'])->value('id');
        if (! $roleId) {
            throw new LogicException("The {$definition['role']} role is missing. Run RolesSeeder first.");
        }

        DB::table('users')->updateOrInsert(
            ['username' => $definition['username']],
            [
                'password' => Hash::make($definition['password']),
                'role_id' => $roleId,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $user = User::query()->where('username', $definition['username'])->firstOrFail();
        DB::table('user_profiles')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'first_name' => $definition['first_name'], 'middle_name' => null, 'last_name' => $definition['last_name'],
                'suffix' => null, 'gender' => 'Prefer not to say', 'birth_date' => null, 'civil_status' => null,
                'email' => $definition['email'], 'contact_number' => null, 'address' => null, 'profile_photo' => null,
                'nationality' => 'Filipino', 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        return $user->fresh(['role', 'profile']);
    }

    private function migrateLegacyFixtureUsernames(): void
    {
        foreach (['event_moderator' => 'event_registered_moderator', 'event_classmayor' => 'event_requested_moderator'] as $old => $new) {
            if (! DB::table('users')->where('username', $new)->exists()) {
                DB::table('users')->where('username', $old)->update(['username' => $new, 'updated_at' => now()]);
            }
        }
    }

    private function seedProfessorIdentity(User $user, string $employeeNumber): void
    {
        $departmentId = DB::table('departments')->where('status', 'active')->orderBy('id')->value('id');
        if (! $departmentId) {
            throw new LogicException('An active Department is required for Event Professor test accounts.');
        }

        DB::table('professors')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'user_profile_id' => $user->profile->id, 'department_id' => $departmentId,
                'employee_number' => $employeeNumber, 'position' => 'Event Validation Professor',
                'specialization' => 'Event Operations', 'employment_status' => 'full_time', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    private function seedStudentIdentity(User $user, string $studentNumber): void
    {
        $curriculum = Curriculum::query()
            ->where('status', 'active')
            ->whereHas('course', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->first() ?: Curriculum::query()->orderBy('id')->firstOrFail();
        $course = Course::query()->findOrFail($curriculum->course_id);

        DB::table('students')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'user_profile_id' => $user->profile->id, 'course_id' => $course->id,
                'curriculum_id' => $curriculum->id, 'student_number' => $studentNumber,
                'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular',
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    /** @param array<string, User> $accounts */
    private function printSummary(Event $event, array $accounts): void
    {
        $rows = [
            ['Coordinator', $accounts['coordinator'], self::ACCOUNTS['coordinator']['password'], 'Coordinator', 'Desktop'],
            ['Semi-Coordinator', $accounts['semi_coordinator'], self::ACCOUNTS['semi_coordinator']['password'], 'Semi-Coordinator', 'Desktop'],
            ['Registered Moderator', $accounts['registered_moderator'], self::ACCOUNTS['registered_moderator']['password'], 'Registered Moderator', 'Mobile'],
            ['Event Staff', $accounts['staff'], self::ACCOUNTS['staff']['password'], 'Event Staff / Manual Verification', 'Mobile'],
            ['Professor', $accounts['professor'], self::ACCOUNTS['professor']['password'], 'None', 'Mobile / Web promotion'],
            ['Requested Moderator', $accounts['requested_moderator'], self::ACCOUNTS['requested_moderator']['password'], 'Requested Moderator / Class Mayor', 'Mobile'],
            ['Student', $accounts['student'], self::ACCOUNTS['student']['password'], 'None', 'Mobile / Web promotion'],
        ];

        $this->command?->info("Event: {$event->title} (#{$event->id})");
        $this->command?->table(
            ['Persona', 'Username', 'Password', 'Global role', 'Event responsibility', 'Platform'],
            array_map(fn (array $row) => [$row[0], $row[1]->username, $row[2], $row[1]->role->role_name, $row[3], $row[4]], $rows),
        );
        $this->command?->warn('LOCAL DEVELOPMENT ONLY. These deterministic credentials must never be used in production.');
    }
}

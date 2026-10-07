<?php

namespace Tests\Feature;

use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use Database\Seeders\AcademicYearsSeeder;
use Database\Seeders\CoursesSeeder;
use Database\Seeders\CurriculumsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\ProfessorsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SemestersSeeder;
use Database\Seeders\StudentsSeeder;
use Database\Seeders\SubjectsSeeder;
use Database\Seeders\UserProfilesSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GradingProfessorSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            DepartmentsSeeder::class,
            AcademicYearsSeeder::class,
            SemestersSeeder::class,
            CoursesSeeder::class,
            CurriculumsSeeder::class,
            SubjectsSeeder::class,
            UsersSeeder::class,
            UserProfilesSeeder::class,
            StudentsSeeder::class,
        ]);
    }

    public function test_professor1_identity_class_and_roster_are_seeded_idempotently(): void
    {
        $passwordHash = User::query()->where('username', 'professor1')->value('password');
        $this->seed(ProfessorsSeeder::class);
        $this->seed(ProfessorsSeeder::class);

        $user = User::query()->where('username', 'professor1')->with(['role', 'profile'])->firstOrFail();
        $professor = Professor::query()->where('user_id', $user->id)->firstOrFail();
        $assignment = SectionSubject::query()->where('professor_id', $professor->id)->firstOrFail();

        $this->assertSame(Role::PROFESSOR, $user->role->role_name);
        $this->assertSame('active', $user->status);
        $this->assertSame($passwordHash, $user->password);
        $this->assertSame($user->profile->id, $professor->user_profile_id);
        $this->assertSame('FAC-2026-001', $professor->employee_number);
        $this->assertSame('active', $professor->status);
        $this->assertSame(1, Professor::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, Professor::query()->where('employee_number', 'FAC-2026-001')->count());
        $this->assertSame(0, Professor::query()->whereHas('user', fn ($query) => $query->where('username', 'registrar1'))->count());
        $this->assertSame(1, SectionSubject::query()->where('professor_id', $professor->id)->count());
        $this->assertSame(1, DB::table('enrollments')->count());
        $this->assertSame(1, DB::table('enrollment_subjects')->count());
        $this->assertSame(0, DB::table('grade_sheets')->count());

        Sanctum::actingAs($user);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/classes')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.student_count', 1)
            ->assertJsonPath('data.data.0.subject_code', 'IT101');
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/grading/classes/'.$assignment->id.'/workspace')
            ->assertOk()
            ->assertJsonCount(1, 'data.roster')
            ->assertJsonPath('data.roster.0.student_number', '26-00001');
        $this->assertDatabaseCount('grade_sheets', 1);
    }

    public function test_legacy_registrar_link_is_repaired_in_place(): void
    {
        $registrar = User::query()->where('username', 'registrar1')->with('profile')->firstOrFail();
        $legacy = Professor::query()->create([
            'user_id' => $registrar->id,
            'user_profile_id' => $registrar->profile->id,
            'department_id' => DB::table('departments')->where('department_code', 'ICS')->value('id'),
            'employee_number' => 'FAC-2026-001',
            'position' => 'Instructor I',
            'specialization' => 'Software Development',
            'employment_status' => 'full_time',
            'status' => 'active',
        ]);

        $this->seed(ProfessorsSeeder::class);

        $professorUser = User::query()->where('username', 'professor1')->with('profile')->firstOrFail();
        $repaired = Professor::query()->where('employee_number', 'FAC-2026-001')->firstOrFail();
        $this->assertSame($legacy->id, $repaired->id);
        $this->assertSame($professorUser->id, $repaired->user_id);
        $this->assertSame($professorUser->profile->id, $repaired->user_profile_id);
        $this->assertSame(0, Professor::query()->where('user_id', $registrar->id)->count());
    }

    public function test_missing_professor_profile_returns_clear_domain_response(): void
    {
        $role = Role::query()->where('role_name', Role::PROFESSOR)->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/classes')
            ->assertConflict()
            ->assertJsonPath('message', 'Your academic Professor profile is not configured. Contact the Registrar before using Grading.');
    }
}

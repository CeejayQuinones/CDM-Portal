<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_list_search_filter_and_view_student_records(): void
    {
        $registrar = $this->createUserWithRole(Role::REGISTRAR_STAFF);
        $firstStudent = $this->createStudent('2026-000001', 'Alicia', 'Reyes', 'regular', 1);
        $this->createStudent('2026-000002', 'Bruno', 'Santos', 'graduated', 4);

        Sanctum::actingAs($registrar);

        $this->getJson('/api/students?search=Alicia&year_level=1&student_status=regular')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $firstStudent->id)
            ->assertJsonPath('data.0.full_name', 'Alicia Reyes')
            ->assertJsonPath('data.0.course.code', 'BSIT')
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/students/{$firstStudent->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.student_number', '2026-000001')
            ->assertJsonPath('data.curriculum.code', 'BSIT-2026')
            ->assertJsonPath('data.department.code', 'ICS')
            ->assertJsonPath('data.academic_year.school_year', '2026-2027')
            ->assertJsonPath('data.semester.name', 'First Semester')
            ->assertJsonPath('data.account.role', Role::STUDENT)
            ->assertJsonPath('data.account.is_first_login', true)
            ->assertJsonPath('data.enrollment_status', 'enrolled')
            ->assertJsonPath('data.profile.email', 'alicia.reyes@example.com');

        $this->patchJson("/api/students/{$firstStudent->id}", [
            'first_name' => 'Alice',
            'last_name' => 'Reyes',
            'course_id' => $firstStudent->course_id,
            'year_level' => 2,
            'student_status' => 'irregular',
            'contact_number' => '09171234567',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.first_name', 'Alice')
            ->assertJsonPath('data.year_level', 2)
            ->assertJsonPath('data.student_status', 'irregular');
    }

    public function test_admin_can_access_student_records_and_other_roles_are_unauthorized(): void
    {
        $admin = $this->createUserWithRole(Role::ADMIN);
        $student = $this->createStudent('2026-000003', 'Carla', 'Dela Cruz', 'irregular', 2);

        Sanctum::actingAs($admin);
        $this->getJson('/api/students')->assertOk();

        Sanctum::actingAs($this->createUserWithRole(Role::STUDENT));
        $this->getJson('/api/students')->assertForbidden()
            ->assertJsonPath('success', false);
        $this->getJson("/api/students/{$student->id}")->assertForbidden();
    }

    public function test_authorized_user_receives_not_found_for_a_missing_student(): void
    {
        Sanctum::actingAs($this->createUserWithRole(Role::ADMIN));

        $this->getJson('/api/students/999999')->assertNotFound();
    }

    public function test_registrar_can_view_a_students_read_only_document_list(): void
    {
        $registrar = $this->createUserWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent('2026-000004', 'Donna', 'Santos', 'regular', 1);
        Sanctum::actingAs($registrar);

        $this->getJson("/api/students/{$student->id}/documents")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.student.id', $student->id)
            ->assertJsonPath('data.documents', []);
    }

    private function createStudent(string $number, string $firstName, string $lastName, string $status, int $yearLevel): Student
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1,
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->createUserWithRole(Role::STUDENT);
        $profile = UserProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => strtolower("{$firstName}.{$lastName}@example.com"),
        ]);
        $course = Course::query()->firstOrCreate(
            ['course_code' => 'BSIT'],
            ['department_id' => 1, 'course_name' => 'Bachelor of Science in Information Technology', 'years' => 4, 'status' => 'active'],
        );
        $curriculum = Curriculum::query()->firstOrCreate(
            ['curriculum_code' => 'BSIT-2026'],
            ['course_id' => $course->id, 'curriculum_name' => 'BSIT Curriculum 2026', 'effective_year' => 2026, 'status' => 'active'],
        );

        $student = Student::query()->create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'course_id' => $course->id,
            'curriculum_id' => $curriculum->id,
            'student_number' => $number,
            'admission_date' => '2026-08-01',
            'year_level' => $yearLevel,
            'student_status' => $status,
        ]);

        DB::table('academic_years')->insertOrIgnore([
            'id' => 1,
            'school_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('semesters')->insertOrIgnore([
            'id' => 1,
            'semester_name' => 'First Semester',
            'semester_order' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sections')->insertOrIgnore([
            'id' => 1,
            'course_id' => $course->id,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'section_name' => 'BSIT-1A',
            'year_level' => 1,
            'capacity' => 40,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'student_id' => $student->id,
            'section_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'enrollment_date' => '2026-08-01',
            'status' => 'enrolled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $student;
    }

    private function createUserWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

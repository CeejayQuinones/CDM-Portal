<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeriodGradeApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_submitted_period_grade_stays_out_of_the_risk_score_until_the_registrar_approves_it(): void
    {
        [$professor, $registrar, $admin, $studentId] = $this->seedGradePath();

        $this->actingAs($professor)
            ->postJson("/api/monitoring/students/{$studentId}/period-grades", [
                'subject_code' => 'IT101',
                'period' => 'Midterm',
                'grade' => 70,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame('submitted', Grade::query()->value('status'));

        $this->actingAs($professor)
            ->getJson('/api/monitoring/early-warnings')
            ->assertOk()
            ->assertJsonPath('data.students.0.subjects', []);

        $this->actingAs($admin)
            ->postJson('/api/monitoring/grade-submissions/approve-all')
            ->assertForbidden();

        $this->actingAs($registrar)
            ->getJson('/api/monitoring/grade-submissions')
            ->assertOk()
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.grades.0.subject_code', 'IT101')
            ->assertJsonPath('data.grades.0.period', 'Midterm')
            ->assertJsonPath('data.grades.0.grade', 70);

        $this->actingAs($registrar)
            ->postJson('/api/monitoring/grade-submissions/approve-all')
            ->assertOk()
            ->assertJsonPath('data.approved', 1);

        $this->assertSame('approved', Grade::query()->value('status'));

        $this->actingAs($professor)
            ->getJson('/api/monitoring/early-warnings')
            ->assertOk()
            ->assertJsonPath('data.students.0.subjects.0.periods.Midterm', 70);

        $this->actingAs($professor)
            ->postJson("/api/monitoring/students/{$studentId}/period-grades", [
                'subject_code' => 'IT101',
                'period' => 'Prelim',
                'grade' => 80,
            ])
            ->assertStatus(422);

        $this->actingAs($professor)
            ->getJson('/api/monitoring/gradebook')
            ->assertOk()
            ->assertJsonPath('data.rows.0.student_number', '26-00001')
            ->assertJsonPath('data.rows.0.year_level', 1)
            ->assertJsonPath('data.rows.0.section_name', 'BSIT-1A')
            ->assertJsonPath('data.rows.0.course_code', 'BSIT')
            ->assertJsonPath('data.rows.0.midterm_status', 'approved');

        $this->actingAs($professor)
            ->get('/api/monitoring/gradebook/export')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($professor)
            ->postJson("/api/monitoring/students/{$studentId}/period-grades", [
                'subject_code' => 'IT101',
                'period' => 'Midterm',
                'grade' => 90,
            ])
            ->assertStatus(422);
    }

    /** @return array{0: User, 1: User, 2: User, 3: int} */
    private function seedGradePath(): array
    {
        $professorRole = Role::query()->create(['role_name' => Role::PROFESSOR]);
        $registrarRole = Role::query()->create(['role_name' => Role::REGISTRAR_STAFF]);
        $adminRole = Role::query()->create(['role_name' => Role::ADMIN]);
        $studentRole = Role::query()->create(['role_name' => Role::STUDENT]);

        $professor = User::query()->create([
            'username' => 'professor1',
            'password' => 'Password123!',
            'role_id' => $professorRole->id,
            'status' => 'active',
        ]);
        $registrar = User::query()->create([
            'username' => 'registrar1',
            'password' => 'Password123!',
            'role_id' => $registrarRole->id,
            'status' => 'active',
        ]);
        $admin = User::query()->create([
            'username' => 'admin',
            'password' => 'Password123!',
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);
        $studentUser = User::query()->create([
            'username' => 'student1',
            'password' => 'Password123!',
            'role_id' => $studentRole->id,
            'status' => 'active',
        ]);

        $professorProfile = DB::table('user_profiles')->insertGetId([
            'user_id' => $professor->id,
            'first_name' => 'Juan',
            'last_name' => 'Reyes',
            'gender' => 'Male',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentProfile = DB::table('user_profiles')->insertGetId([
            'user_id' => $studentUser->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'gender' => 'Male',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $departmentId = DB::table('departments')->insertGetId([
            'department_code' => 'CCS',
            'department_name' => 'Computing',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'department_id' => $departmentId,
            'course_code' => 'BSIT',
            'course_name' => 'Information Technology',
            'years' => 4,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $curriculumId = DB::table('curriculums')->insertGetId([
            'course_id' => $courseId,
            'curriculum_code' => 'BSIT-2026',
            'curriculum_name' => 'BSIT 2026',
            'effective_year' => 2026,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $yearId = DB::table('academic_years')->insertGetId([
            'school_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $semesterId = DB::table('semesters')->insertGetId([
            'semester_name' => 'First Semester',
            'semester_order' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $professorId = DB::table('professors')->insertGetId([
            'user_id' => $professor->id,
            'user_profile_id' => $professorProfile,
            'department_id' => $departmentId,
            'employee_number' => 'EMP-1',
            'employment_status' => 'full_time',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = DB::table('sections')->insertGetId([
            'course_id' => $courseId,
            'academic_year_id' => $yearId,
            'semester_id' => $semesterId,
            'section_name' => 'BSIT-1A',
            'year_level' => 1,
            'adviser_id' => $professorId,
            'capacity' => 40,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'user_profile_id' => $studentProfile,
            'course_id' => $courseId,
            'curriculum_id' => $curriculumId,
            'student_number' => '26-00001',
            'admission_date' => '2026-08-01',
            'student_status' => 'regular',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'section_id' => $sectionId,
            'academic_year_id' => $yearId,
            'semester_id' => $semesterId,
            'enrollment_date' => '2026-08-15',
            'status' => 'enrolled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $subjectId = DB::table('subjects')->insertGetId([
            'curriculum_id' => $curriculumId,
            'semester_id' => $semesterId,
            'subject_code' => 'IT101',
            'subject_name' => 'Introduction to Computing',
            'year_level' => 1,
            'units' => 3,
            'lecture_hours' => 3,
            'laboratory_hours' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollment_subjects')->insert([
            'enrollment_id' => $enrollmentId,
            'subject_id' => $subjectId,
            'professor_id' => $professorId,
            'subject_status' => 'enrolled',
            'remarks' => 'In Progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('grading_periods')->insert([
            ['period_name' => 'Prelim', 'period_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['period_name' => 'Midterm', 'period_order' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['period_name' => 'Final', 'period_order' => 3, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$professor, $registrar, $admin, $studentId];
    }
}

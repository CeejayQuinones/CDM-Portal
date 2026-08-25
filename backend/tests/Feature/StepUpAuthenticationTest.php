<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StepUpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_read_only_student_and_cabinet_pages_do_not_require_step_up(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent();
        $cabinet = $this->createCabinet();
        $token = $registrar->createToken('read-only')->plainTextToken;

        $this->withToken($token)->getJson('/api/students')->assertOk();
        $this->withToken($token)->getJson("/api/students/{$student->id}")->assertOk();
        $this->withToken($token)->getJson("/api/students/{$student->id}/documents")->assertOk();
        $this->withToken($token)->getJson('/api/registrar/cabinets')->assertOk();
        $this->withToken($token)->getJson('/api/registrar/cabinet-slots/'.$cabinet->slots()->firstOrFail()->id)->assertOk();
    }

    public function test_sensitive_student_edit_is_blocked_until_correct_password_is_verified(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent();
        $token = $registrar->createToken('student-edit')->plainTextToken;

        $this->withToken($token)->patchJson("/api/students/{$student->id}", $this->updatePayload($student))
            ->assertStatus(428)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->withToken($token)->postJson('/api/step-up/verify', ['password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->withToken($token)->postJson('/api/step-up/verify', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Identity verified.')
            ->assertJsonStructure(['data' => ['expires_at']]);

        $this->withToken($token)->patchJson("/api/students/{$student->id}", [
            ...$this->updatePayload($student),
            'student_number' => '2026-000099',
            'first_name' => 'Updated',
            'year_level' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.student_number', '2026-000099')
            ->assertJsonPath('data.profile.first_name', 'Updated')
            ->assertJsonPath('data.year_level', 2);
    }

    public function test_expired_step_up_is_blocked(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent();
        $token = $registrar->createToken('expiry')->plainTextToken;

        $this->withToken($token)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();

        $this->travel(6)->minutes();

        $this->withToken($token)->patchJson("/api/students/{$student->id}", $this->updatePayload($student))
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }

    public function test_step_up_is_scoped_to_the_user_and_bearer_token(): void
    {
        $firstRegistrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $secondRegistrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent();
        $firstToken = $firstRegistrar->createToken('first')->plainTextToken;
        $sameUserOtherToken = $firstRegistrar->createToken('first-other')->plainTextToken;
        $secondToken = $secondRegistrar->createToken('second')->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();

        $this->withToken($sameUserOtherToken)->patchJson("/api/students/{$student->id}", $this->updatePayload($student))
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->withToken($secondToken)->patchJson("/api/students/{$student->id}", $this->updatePayload($student))
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }

    public function test_existing_role_checks_still_apply_to_students(): void
    {
        $studentRecord = $this->createStudent();
        $studentUser = $this->userWithRole(Role::STUDENT);
        $studentToken = $studentUser->createToken('student')->plainTextToken;

        $this->withToken($studentToken)->patchJson(
            "/api/students/{$studentRecord->id}",
            $this->updatePayload($studentRecord),
        )->assertForbidden();

        $this->withToken($studentToken)->putJson(
            "/api/registrar/students/{$studentRecord->id}/record-location",
            ['cabinet_slot_id' => $this->createCabinet()->slots()->firstOrFail()->id],
        )->assertForbidden();

    }

    public function test_admin_keeps_existing_student_access_but_not_registrar_cabinet_access(): void
    {
        $studentRecord = $this->createStudent();
        $admin = $this->userWithRole(Role::ADMIN);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)->patchJson(
            "/api/students/{$studentRecord->id}",
            $this->updatePayload($studentRecord),
        )->assertOk();

        $this->withToken($adminToken)->getJson('/api/registrar/cabinets')->assertForbidden();
    }

    public function test_physical_record_location_change_requires_step_up(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent();
        $slot = $this->createCabinet()->slots()->firstOrFail();
        $token = $registrar->createToken('record-location')->plainTextToken;
        $endpoint = "/api/registrar/students/{$student->id}/record-location";

        $this->withToken($token)->putJson($endpoint, ['cabinet_slot_id' => $slot->id])
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->withToken($token)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();

        $this->withToken($token)->putJson($endpoint, ['cabinet_slot_id' => $slot->id])
            ->assertOk()
            ->assertJsonPath('data.cabinet_slot.id', $slot->id);
    }

    /** @return array<string, mixed> */
    private function updatePayload(Student $student): array
    {
        return [
            'first_name' => $student->userProfile->first_name,
            'last_name' => $student->userProfile->last_name,
            'course_id' => $student->course_id,
            'year_level' => $student->year_level,
            'student_status' => $student->student_status,
        ];
    }

    private function createCabinet(): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'cabinet_code' => 'A',
            'rows' => 1,
            'columns' => 1,
        ]);
        $cabinet->slots()->create([
            'slot_code' => 'A1',
            'capacity' => 10,
        ]);

        return $cabinet;
    }

    private function createStudent(): Student
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1,
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->userWithRole(Role::STUDENT);
        $profile = UserProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Alicia',
            'last_name' => 'Reyes',
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => "alicia.{$user->id}@example.test",
        ]);
        $course = Course::query()->create([
            'department_id' => 1,
            'course_code' => 'BSIT',
            'course_name' => 'Bachelor of Science in Information Technology',
            'years' => 4,
            'status' => 'active',
        ]);
        $curriculum = Curriculum::query()->create([
            'course_id' => $course->id,
            'curriculum_code' => 'BSIT-2026',
            'curriculum_name' => 'BSIT Curriculum 2026',
            'effective_year' => 2026,
            'status' => 'active',
        ]);

        return Student::query()->create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'course_id' => $course->id,
            'curriculum_id' => $curriculum->id,
            'student_number' => '2026-000001',
            'admission_date' => '2026-08-01',
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            ['role_name' => $roleName],
            ['description' => $roleName],
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }
}

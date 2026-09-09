<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_load_only_own_safe_settings(): void
    {
        $student = $this->studentFixture();
        $this->actingAs($student->user)->getJson('/api/student/settings')
            ->assertOk()->assertJsonPath('data.official.student_number', '2026-0001')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.verification_code_hash');
    }

    public function test_non_student_roles_are_denied(): void
    {
        foreach ([Role::GUEST, Role::REGISTRAR_STAFF, Role::PROFESSOR, Role::ADMIN] as $roleName) {
            $role = Role::query()->firstOrCreate(['role_name' => $roleName]);
            $user = User::factory()->create(['role_id' => $role->id]);
            $this->actingAs($user)->getJson('/api/student/settings')->assertForbidden();
        }
    }

    public function test_settings_routes_have_no_arbitrary_student_identifier(): void
    {
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutesByMethod()['GET'] ?? [])
            ->contains(fn ($route) => $route->uri() === 'api/student/{student}/settings'));
    }

    public function test_profile_contact_and_preferences_persist_without_changing_official_data(): void
    {
        $student = $this->studentFixture();
        $this->actingAs($student->user)->patchJson('/api/student/settings/profile', ['preferred_display_name' => 'Kai', 'bio' => 'Focused learner', 'student_number' => 'HACKED'])->assertOk();
        $this->actingAs($student->user)->patchJson('/api/student/settings/contact', ['email' => 'kai@example.test', 'contact_number' => '09170000000', 'address' => 'Montalban'])->assertOk();
        $this->actingAs($student->user)->patchJson('/api/student/settings/preferences', ['notification_preferences' => ['email' => true], 'academic_preferences' => ['ai_suggestions' => true], 'appearance' => 'dark'])->assertOk();
        $this->assertDatabaseHas('student_settings', ['student_id' => $student->id, 'preferred_display_name' => 'Kai', 'appearance' => 'dark']);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'student_number' => '2026-0001']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $student->user_id, 'email' => 'kai@example.test']);
    }

    public function test_avatar_validation_and_removal_use_public_storage(): void
    {
        Storage::fake('public');
        $student = $this->studentFixture();
        $this->actingAs($student->user)->post('/api/student/settings/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png')])->assertOk();
        $path = $student->fresh()->userProfile->profile_photo;
        Storage::disk('public')->assertExists($path);
        $this->actingAs($student->user)->post('/api/student/settings/avatar', ['avatar' => UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->actingAs($student->user)->post('/api/student/settings/avatar', ['avatar' => UploadedFile::fake()->image('large.png')->size(2049)])->assertUnprocessable();
        $this->actingAs($student->user)->deleteJson('/api/student/settings/avatar')->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $student = $this->studentFixture();
        $this->actingAs($student->user)->postJson('/api/change-password', ['current_password' => 'Password123!', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $student->user->fresh()->password));
        $this->actingAs($student->user)->postJson('/api/change-password', ['current_password' => 'wrong', 'password' => 'OtherPassword123!', 'password_confirmation' => 'OtherPassword123!'])->assertUnprocessable();
    }

    public function test_document_request_and_upcoming_appointment_summary_is_correct(): void
    {
        $student = $this->studentFixture();
        $type = DB::table('document_types')->insertGetId(['document_name' => 'Good Moral', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('student_documents')->insert(['student_id' => $student->id, 'document_type_id' => $type, 'verification_status' => 'verified', 'availability_status' => 'available', 'submitted_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $active = DB::table('document_requests')->insertGetId(['student_id' => $student->id, 'document_type_id' => $type, 'quantity' => 1, 'total_fee' => 0, 'purpose' => 'Test', 'status' => 'approved', 'request_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('document_requests')->insert(['student_id' => $student->id, 'document_type_id' => $type, 'quantity' => 1, 'total_fee' => 0, 'purpose' => 'Test', 'status' => 'completed', 'request_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('appointments')->insert(['student_id' => $student->id, 'document_request_id' => $active, 'appointment_date' => now()->addDay()->toDateString(), 'appointment_time' => '09:00:00', 'purpose' => 'Release', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($student->user)->getJson('/api/student/settings')->assertOk()
            ->assertJsonPath('data.document_status.complete', 1)->assertJsonPath('data.document_status.active_requests', 1)
            ->assertJsonPath('data.document_status.completed_requests', 1)->assertJsonPath('data.document_status.upcoming_appointment', now()->addDay()->toDateString());
    }

    private function studentFixture(): Student
    {
        $role = Role::query()->firstOrCreate(['role_name' => Role::STUDENT]);
        $user = User::factory()->create(['role_id' => $role->id, 'password' => Hash::make('Password123!'), 'status' => 'active']);
        $now = now();
        $department = DB::table('departments')->insertGetId(['department_code' => 'IT', 'department_name' => 'IT', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $course = DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => 'BSIT', 'course_name' => 'BSIT', 'years' => 4, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'BSIT-26', 'curriculum_name' => 'BSIT', 'effective_year' => 2026, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => 'Test', 'last_name' => 'Student', 'gender' => 'Prefer not to say', 'created_at' => $now, 'updated_at' => $now]);

        return Student::query()->create(['user_id' => $user->id, 'user_profile_id' => DB::table('user_profiles')->where('user_id', $user->id)->value('id'), 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => '2026-0001', 'admission_date' => now()->toDateString(), 'year_level' => 1, 'student_status' => 'regular']);
    }
}

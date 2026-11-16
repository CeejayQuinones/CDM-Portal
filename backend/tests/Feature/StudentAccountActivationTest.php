<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCodeMail;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\StudentAccountActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentAccountActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
    }

    public function test_only_prepared_record_and_original_email_can_activate(): void
    {
        Mail::fake();
        $student = $this->student('26-30001', 'original@gmail.com');
        $payload = ['student_number' => '26-30001', 'email' => 'wrong@gmail.com'];

        $this->postJson('/api/student-account/send-code', $payload)->assertOk();
        Mail::assertNothingSent();
        $this->postJson('/api/student-account/send-code', ['student_number' => '26-30001', 'email' => 'original@gmail.com'])->assertOk();
        Mail::assertNothingSent();

        $this->prepare($student);
        $this->postJson('/api/student-account/send-code', $payload)->assertOk();
        Mail::assertNothingSent();
        $this->travel(61)->seconds();
        $this->postJson('/api/student-account/send-code', ['student_number' => '26-30001', 'email' => 'original@gmail.com'])->assertOk();
        $code = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($message) use (&$code): bool {
            $code = $message->code;

            return true;
        });
        $this->postJson('/api/student-account/verify-code', [
            'student_number' => '26-30001', 'email' => 'original@gmail.com', 'code' => '000000',
        ])->assertUnprocessable();
        $token = $this->postJson('/api/student-account/verify-code', [
            'student_number' => '26-30001', 'email' => 'original@gmail.com', 'code' => $code,
        ])->assertOk()->json('data.verification_token');
        $this->assertSame('inactive', $student->user->fresh()->status);
        $this->postJson('/api/student-account/activate', [
            'student_number' => '26-30001', 'email' => 'wrong@gmail.com', 'verification_token' => $token,
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ])->assertUnprocessable();
        $this->postJson('/api/student-account/activate', [
            'student_number' => '26-30001', 'email' => 'original@gmail.com', 'verification_token' => $token,
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ])->assertOk();
        $this->assertSame('active', $student->user->fresh()->status);
        $this->assertTrue(Hash::check('SecurePassword123!', $student->user->fresh()->password));
        $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => 'student_account_activated']);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->travel(61)->seconds();
        config(['mail.default' => 'smtp']);
        $this->postJson('/api/login', [
            'username' => $student->user->username, 'password' => 'SecurePassword123!',
        ])->assertUnauthorized();
        $challenge = $this->postJson('/api/login', [
            'username' => '26-30001', 'password' => 'SecurePassword123!',
        ])->assertAccepted();
        $this->getJson('/api/me')->assertUnauthorized();
        $loginCode = null;
        Mail::assertSent(LoginVerificationCodeMail::class, function ($message) use (&$loginCode): bool {
            $loginCode = $message->code;

            return true;
        });
        $this->postJson('/api/auth/login/otp/verify', [
            'challenge_id' => $challenge->json('data.challenge_id'), 'code' => $loginCode,
        ])->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/api/student-account/activate', [
            'student_number' => '26-30001', 'email' => 'original@gmail.com', 'verification_token' => $token,
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ])->assertUnprocessable();
    }

    public function test_registrar_correction_requires_physical_id_attestation_and_invalidates_old_code(): void
    {
        Mail::fake();
        $student = $this->student('26-30002', 'old@gmail.com');
        $this->prepare($student);
        $this->postJson('/api/student-account/send-code', ['student_number' => '26-30002', 'email' => 'old@gmail.com'])->assertOk();
        $oldCode = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($message) use (&$oldCode): bool {
            $oldCode = $message->code;

            return true;
        });

        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $authToken = $registrar->createToken('registrar', ['client:desktop'])->plainTextToken;
        $url = '/api/students/'.$student->id.'/activation-email';
        $this->withToken($authToken)->patchJson($url, [
            'email' => 'new@gmail.com', 'identity_check_reference' => 'Appointment 17',
            'physical_school_id_checked' => true,
        ])->assertStatus(428);
        $this->withToken($authToken)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->withToken($authToken)->patchJson($url, [
            'email' => 'new@gmail.com', 'identity_check_reference' => 'Appointment 17',
            'physical_school_id_checked' => false,
        ])->assertUnprocessable();
        $this->withToken($authToken)->patchJson($url, [
            'email' => 'new@gmail.com', 'identity_check_reference' => 'Appointment 17',
            'physical_school_id_checked' => true,
        ])->assertOk();
        $this->assertSame('new@gmail.com', $student->userProfile->fresh()->email);
        $this->postJson('/api/student-account/verify-code', [
            'student_number' => '26-30002', 'email' => 'new@gmail.com', 'code' => $oldCode,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => 'student_account_activation_email_corrected']);
    }

    public function test_active_existing_student_account_cannot_be_prepared_or_claimed(): void
    {
        $student = $this->student('26-30003', 'existing@gmail.com', 'active');
        $this->expectException(ValidationException::class);
        app(StudentAccountActivationService::class)->prepare($this->user(Role::REGISTRAR_STAFF), $student, 'Enrollment 3');
    }

    public function test_registrar_can_correct_missing_email_after_school_id_check_before_preparation(): void
    {
        $student = $this->student('26-30004', null);
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $authToken = $registrar->createToken('registrar', ['client:desktop'])->plainTextToken;
        $this->withToken($authToken)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->withToken($authToken)->patchJson('/api/students/'.$student->id.'/activation-email', [
            'email' => 'found@gmail.com', 'identity_check_reference' => 'In-person ID 4',
            'physical_school_id_checked' => true,
        ])->assertOk();
        $this->assertDatabaseHas('student_account_activations', ['student_id' => $student->id]);
        $this->assertSame('found@gmail.com', $student->userProfile->fresh()->email);
    }

    public function test_expired_code_cannot_activate_an_account(): void
    {
        Mail::fake();
        $student = $this->student('26-30005', 'expired@gmail.com');
        $this->prepare($student);
        $this->postJson('/api/student-account/send-code', [
            'student_number' => '26-30005', 'email' => 'expired@gmail.com',
        ])->assertOk();
        $code = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($message) use (&$code): bool {
            $code = $message->code;

            return true;
        });
        $this->travel(11)->minutes();
        $this->postJson('/api/student-account/verify-code', [
            'student_number' => '26-30005', 'email' => 'expired@gmail.com', 'code' => $code,
        ])->assertUnprocessable();
        $this->assertSame('inactive', $student->user->fresh()->status);
    }

    public function test_activation_email_is_not_accepted_without_a_real_mailer(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $student = $this->student('26-30006', 'student@gmail.com');
        $this->prepare($student);
        $this->postJson('/api/student-account/send-code', [
            'student_number' => '26-30006', 'email' => 'student@gmail.com',
        ])->assertStatus(503);
        Mail::assertNothingSent();
    }

    private function prepare(Student $student): void
    {
        app(StudentAccountActivationService::class)->prepare($this->user(Role::REGISTRAR_STAFF), $student, 'Enrollment file 1');
    }

    private function student(string $number, ?string $email, string $status = 'inactive'): Student
    {
        $user = $this->user(Role::STUDENT, $status);
        $profile = UserProfile::query()->create([
            'user_id' => $user->id, 'first_name' => 'Test', 'last_name' => 'Student',
            'gender' => 'Prefer not to say', 'email' => $email,
        ]);
        DB::table('departments')->insertOrIgnore(['id' => 1, 'department_code' => 'IT', 'department_name' => 'IT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('courses')->insertGetId(['department_id' => 1, 'course_code' => 'BSIT-'.$number, 'course_name' => 'BSIT', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'CUR-'.$number, 'curriculum_name' => 'Curriculum', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => $number, 'admission_date' => today(), 'year_level' => 1, 'student_status' => 'regular']);
    }

    private function user(string $role, string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $role]);

        return User::factory()->create(['role_id' => $role->id, 'status' => $status]);
    }
}

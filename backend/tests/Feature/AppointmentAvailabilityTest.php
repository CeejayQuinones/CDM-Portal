<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentAvailabilitySetting;
use App\Models\AppointmentBlockedDate;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $studentSequence = 2000;

    public function test_saturday_is_rejected_when_saturday_blocking_is_enabled(): void
    {
        [$student, $documentRequest] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $this->book($documentRequest, $this->nextSaturday())
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'Appointments are unavailable on Saturdays.');
    }

    public function test_saturday_is_allowed_when_saturday_blocking_is_disabled(): void
    {
        AppointmentAvailabilitySetting::query()->findOrFail(1)->update(['block_saturday' => false]);
        [$student, $documentRequest] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $this->book($documentRequest, $this->nextSaturday())->assertCreated();
    }

    public function test_sunday_is_rejected_when_sunday_blocking_is_enabled(): void
    {
        [$student, $documentRequest] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $this->book($documentRequest, $this->nextSunday())
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'Appointments are unavailable on Sundays.');
    }

    public function test_sunday_is_allowed_when_sunday_blocking_is_disabled(): void
    {
        AppointmentAvailabilitySetting::query()->findOrFail(1)->update(['block_sunday' => false]);
        [$student, $documentRequest] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $this->book($documentRequest, $this->nextSunday())->assertCreated();
    }

    public function test_manually_blocked_saturday_is_rejected_when_saturday_blocking_is_disabled(): void
    {
        $date = $this->nextSaturday();
        AppointmentAvailabilitySetting::query()->findOrFail(1)->update(['block_saturday' => false]);
        $this->blockDate($date, 'Campus event');
        [$student, $documentRequest] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $this->book($documentRequest, $date)
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'Campus event is unavailable for appointments.');
    }

    public function test_manually_blocked_sunday_is_rejected_when_sunday_blocking_is_disabled(): void
    {
        $date = $this->nextSunday();
        AppointmentAvailabilitySetting::query()->findOrFail(1)->update(['block_sunday' => false]);
        $this->blockDate($date, 'Office closure');
        [$student, $documentRequest] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $this->book($documentRequest, $date)
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'Office closure is unavailable for appointments.');
    }

    public function test_registrar_can_read_and_change_weekend_settings(): void
    {
        $registrar = $this->createRegistrar();
        Sanctum::actingAs($registrar->user);

        $this->getJson('/api/registrar/appointment-availability/settings')
            ->assertOk()
            ->assertJsonPath('data.block_saturday', true)
            ->assertJsonPath('data.block_sunday', true);

        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => 'not-a-boolean',
            'block_sunday' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('block_saturday');

        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => false,
            'block_sunday' => true,
        ])->assertOk()
            ->assertJsonPath('data.block_saturday', false)
            ->assertJsonPath('data.block_sunday', true);

        $this->assertDatabaseHas('appointment_availability_settings', [
            'id' => 1,
            'block_saturday' => false,
            'block_sunday' => true,
            'updated_by' => $registrar->user_id,
        ]);
    }

    public function test_student_and_admin_cannot_change_registrar_weekend_settings(): void
    {
        [$student] = $this->appointmentRequest();
        $payload = ['block_saturday' => false, 'block_sunday' => false];

        Sanctum::actingAs($student->user);
        $this->patchJson('/api/registrar/appointment-availability/settings', $payload)->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->patchJson('/api/registrar/appointment-availability/settings', $payload)->assertForbidden();

        $this->assertDatabaseHas('appointment_availability_settings', [
            'id' => 1,
            'block_saturday' => true,
            'block_sunday' => true,
            'updated_by' => null,
        ]);
    }

    public function test_student_availability_returns_current_settings_and_only_active_blocked_dates(): void
    {
        $activeDate = $this->nextSaturday();
        $inactiveDate = $activeDate->addDay();
        AppointmentAvailabilitySetting::query()->findOrFail(1)->update([
            'block_saturday' => false,
            'block_sunday' => true,
        ]);
        $this->blockDate($activeDate, 'School event');
        $this->blockDate($activeDate->startOfMonth()->addMonth()->addDays(3), 'Different month');
        AppointmentBlockedDate::create([
            'blocked_date' => $inactiveDate,
            'type' => 'maintenance',
            'reason' => 'Inactive block',
            'is_active' => false,
        ]);
        [$student] = $this->appointmentRequest();
        Sanctum::actingAs($student->user);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/appointment-availability?month='.$activeDate->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('data.settings.block_saturday', false)
            ->assertJsonPath('data.settings.block_sunday', true)
            ->assertJsonCount(1, 'data.blocked_dates')
            ->assertJsonPath('data.blocked_dates.0.date', $activeDate->toDateString())
            ->assertJsonPath('data.blocked_dates.0.reason', 'School event');

        $this->assertCount(1, array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'appointment_availability_settings'),
        ));
        $this->assertCount(1, array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'appointment_blocked_dates'),
        ));
    }

    public function test_changing_weekend_settings_does_not_modify_existing_appointments(): void
    {
        [$student, $documentRequest] = $this->appointmentRequest();
        $date = $this->nextSaturday();
        $appointment = Appointment::create([
            'student_id' => $student->id,
            'document_request_id' => $documentRequest->id,
            'appointment_date' => $date,
            'appointment_time' => '10:00',
            'purpose' => 'Document request',
            'status' => 'confirmed',
            'active_slot_key' => $date->toDateString().' 10:00',
        ]);
        $registrar = $this->createRegistrar();
        Sanctum::actingAs($registrar->user);

        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => false,
            'block_sunday' => true,
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame($date->toDateString(), $appointment->appointment_date->toDateString());
        $this->assertSame('10:00', $appointment->appointment_time);
        $this->assertSame('confirmed', $appointment->status);
    }

    private function book(DocumentRequest $documentRequest, CarbonImmutable $date)
    {
        return $this->postJson("/api/document-requests/{$documentRequest->id}/appointments", [
            'appointment_date' => $date->toDateString(),
            'appointment_time' => '09:00',
        ]);
    }

    /** @return array{Student, DocumentRequest} */
    private function appointmentRequest(): array
    {
        $this->studentSequence++;
        $student = $this->createStudent('26-'.$this->studentSequence);
        $type = DocumentType::firstOrCreate(
            ['document_name' => 'Appointment Test Document'],
            [
                'processing_fee' => 0,
                'processing_days' => 1,
                'requires_appointment' => true,
                'status' => 'active',
            ],
        );
        $documentRequest = DocumentRequest::create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'quantity' => 1,
            'total_fee' => 0,
            'status' => 'pending',
            'request_date' => today(),
        ]);

        return [$student, $documentRequest];
    }

    private function blockDate(CarbonImmutable $date, string $reason): AppointmentBlockedDate
    {
        return AppointmentBlockedDate::create([
            'blocked_date' => $date,
            'type' => 'school_event',
            'reason' => $reason,
            'is_active' => true,
        ]);
    }

    private function nextSaturday(): CarbonImmutable
    {
        return CarbonImmutable::today()->next(CarbonImmutable::SATURDAY);
    }

    private function nextSunday(): CarbonImmutable
    {
        return CarbonImmutable::today()->next(CarbonImmutable::SUNDAY);
    }

    private function createStudent(string $number): Student
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
        $profile = UserProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Student',
            'last_name' => str_replace('-', '', $number),
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => $number.'@example.test',
        ]);
        $course = Course::firstOrCreate(
            ['course_code' => 'BSIT'],
            ['department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active'],
        );
        $curriculum = Curriculum::firstOrCreate(
            ['curriculum_code' => 'BSIT-2026'],
            ['course_id' => $course->id, 'curriculum_name' => 'BSIT Curriculum', 'effective_year' => 2026, 'status' => 'active'],
        );

        return Student::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'course_id' => $course->id,
            'curriculum_id' => $curriculum->id,
            'student_number' => $number,
            'admission_date' => '2026-08-01',
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
    }

    private function createRegistrar(): RegistrarStaff
    {
        $user = $this->userWithRole(Role::REGISTRAR_STAFF);
        $profile = UserProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Registrar',
            'last_name' => 'Staff',
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => 'registrar'.$user->id.'@example.test',
        ]);

        return RegistrarStaff::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'employee_number' => 'REG-'.$user->id,
            'position' => 'Registrar Staff',
            'employment_status' => 'regular',
            'status' => 'active',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

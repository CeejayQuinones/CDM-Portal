<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrarDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registrar_dashboard_returns_aggregate_counts_and_limited_existing_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 09:30:00', 'Asia/Manila'));

        $first = $this->createStudent('26-02001', 'Ana', 'Reyes');
        $second = $this->createStudent('26-02002', 'Ben', 'Santos');
        $this->createStudent('26-02003', 'Cara', 'Cruz');
        $documentType = DocumentType::query()->create([
            'document_name' => 'Transcript of Records',
            'processing_fee' => 150,
            'processing_days' => 3,
            'requires_appointment' => true,
            'status' => 'active',
        ]);

        StudentDocument::query()->create([
            'student_id' => $first->id,
            'document_type_id' => $documentType->id,
            'availability_status' => 'missing',
            'verification_status' => 'pending',
        ]);

        $pendingRequest = DocumentRequest::query()->create([
            'student_id' => $first->id,
            'document_type_id' => $documentType->id,
            'quantity' => 1,
            'total_fee' => 150,
            'status' => 'pending',
            'request_date' => '2026-08-26',
        ]);
        $releasedRequest = DocumentRequest::query()->create([
            'student_id' => $second->id,
            'document_type_id' => $documentType->id,
            'quantity' => 1,
            'total_fee' => 150,
            'status' => 'released',
            'request_date' => '2026-08-25',
            'released_at' => now(),
        ]);

        Appointment::query()->create([
            'student_id' => $first->id,
            'document_request_id' => $pendingRequest->id,
            'appointment_date' => '2026-08-26',
            'appointment_time' => '10:00',
            'purpose' => 'Document request',
            'status' => 'pending',
            'active_slot_key' => '2026-08-26 10:00',
        ]);
        Appointment::query()->create([
            'student_id' => $second->id,
            'document_request_id' => $releasedRequest->id,
            'appointment_date' => '2026-08-26',
            'appointment_time' => '09:00',
            'purpose' => 'Document release',
            'status' => 'completed',
        ]);
        Appointment::query()->create([
            'student_id' => $first->id,
            'document_request_id' => $pendingRequest->id,
            'appointment_date' => '2026-08-27',
            'appointment_time' => '11:00',
            'purpose' => 'Document request',
            'status' => 'confirmed',
            'active_slot_key' => '2026-08-27 11:00',
        ]);

        $cabinet = Cabinet::query()->create([
            'cabinet_code' => 'A',
            'description' => 'Registrar records',
            'rows' => 1,
            'columns' => 1,
        ]);
        $slot = $cabinet->slots()->create([
            'slot_code' => 'A1',
            'capacity' => 20,
        ]);
        StudentRecordLocation::query()->create([
            'student_id' => $first->id,
            'cabinet_slot_id' => $slot->id,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($this->createRegistrar()->user);

        $response = $this->getJson('/api/registrar/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.total_students', 3)
            ->assertJsonPath('data.summary.pending_document_requests', 1)
            ->assertJsonPath('data.summary.todays_appointments', 2)
            ->assertJsonPath('data.summary.students_without_record_location', 2)
            ->assertJsonPath('data.summary.students_with_missing_documents', 1)
            ->assertJsonCount(2, 'data.todays_appointments')
            ->assertJsonPath('data.todays_appointments.0.student_number', '26-02002')
            ->assertJsonPath('data.todays_appointments.0.document', 'Transcript of Records')
            ->assertJsonFragment(['type' => 'record_location'])
            ->assertJsonMissing(['appointment_date' => '2026-08-27']);

        $this->assertLessThanOrEqual(8, count($response->json('data.recent_activity')));
    }

    public function test_admin_cannot_access_registrar_dashboard(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::ADMIN));

        $this->getJson('/api/registrar/dashboard')->assertForbidden();
    }

    private function createStudent(string $number, string $firstName, string $lastName): Student
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
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => strtolower("{$firstName}.{$lastName}.{$user->id}@example.test"),
        ]);
        $course = Course::query()->firstOrCreate(
            ['course_code' => 'BSIT'],
            [
                'department_id' => 1,
                'course_name' => 'Information Technology',
                'years' => 4,
                'status' => 'active',
            ],
        );
        $curriculum = Curriculum::query()->firstOrCreate(
            ['curriculum_code' => 'BSIT-2026'],
            [
                'course_id' => $course->id,
                'curriculum_name' => 'BSIT Curriculum',
                'effective_year' => 2026,
                'status' => 'active',
            ],
        );

        return Student::query()->create([
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
        $profile = UserProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Registrar',
            'last_name' => 'Staff',
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => "registrar{$user->id}@example.test",
        ]);

        return RegistrarStaff::query()->create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'employee_number' => "REG-{$user->id}",
            'position' => 'Registrar Staff',
            'employment_status' => 'regular',
            'status' => 'active',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            ['role_name' => $roleName],
            ['description' => $roleName],
        );

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

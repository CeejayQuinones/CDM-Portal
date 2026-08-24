<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrarDocumentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_is_recent_first_and_supports_time_reference_name_and_exact_id_filters(): void
    {
        $this->travelTo(Carbon::parse('2026-08-25 12:00:00'));
        $student = $this->createStudent('26-02001');
        $type = $this->createDocumentType('Transcript of Records');
        $first = $this->createRequest($student, $type, 'pending', '2026-08-20 09:00:00', '2026-08-25 08:00:00');
        $mostRecent = $this->createRequest($student, $type, 'processing', '2026-08-19 09:00:00', '2026-08-25 11:00:00');
        $yesterday = $this->createRequest($student, $type, 'ready_for_release', '2026-08-18 09:00:00', '2026-08-24 15:00:00');

        Sanctum::actingAs($this->createRegistrar()->user);

        $this->getJson('/api/registrar/document-requests')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $mostRecent->id)
            ->assertJsonPath('data.data.0.request_reference', sprintf('REQ-%06d', $mostRecent->id))
            ->assertJsonPath('data.data.1.id', $first->id)
            ->assertJsonPath('data.data.2.id', $yesterday->id);

        $reference = sprintf('REQ-%06d', $first->id);
        $this->getJson('/api/registrar/document-requests?search='.urlencode($reference))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $first->id);
        $this->getJson('/api/registrar/document-requests?search='.urlencode('Student 2602001'))
            ->assertOk()
            ->assertJsonCount(3, 'data.data');
        $this->getJson("/api/registrar/document-requests?request_id={$yesterday->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $yesterday->id);
        $this->getJson('/api/registrar/document-requests?time_filter=today')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
        $this->getJson('/api/registrar/document-requests?time_filter=yesterday')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $yesterday->id);
        $this->getJson('/api/registrar/document-requests?status=released')->assertUnprocessable();
    }

    public function test_time_filters_use_manila_business_days_around_local_midnight(): void
    {
        $this->travelTo(Carbon::create(2026, 8, 25, 0, 30, 0, 'Asia/Manila'));
        $student = $this->createStudent('26-02004');
        $type = $this->createDocumentType('Certificate of Grades');
        $insideToday = $this->createRequest(
            $student,
            $type,
            'processing',
            '2026-08-24 16:10:00',
            '2026-08-24 16:10:00',
            ['approved_at' => '2026-08-24 16:10:00'],
        );
        $insideYesterday = $this->createRequest(
            $student,
            $type,
            'pending',
            '2026-08-24 15:59:00',
            '2026-08-24 15:59:00',
        );
        $todayAppointment = $this->createAppointment($insideToday, 'pending', '2026-08-25', '09:00', '2026-08-24 16:15:00');
        $this->createAppointment($insideYesterday, 'pending', '2026-08-24', '10:00', '2026-08-24 15:50:00');

        Sanctum::actingAs($this->createRegistrar()->user);

        $this->getJson('/api/registrar/document-requests?time_filter=today')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $insideToday->id);
        $this->getJson('/api/registrar/document-requests?time_filter=yesterday')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $insideYesterday->id);
        $this->getJson('/api/registrar/appointments?group=today')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $todayAppointment->id);
        $this->getJson('/api/registrar/appointments?time_filter=today')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $todayAppointment->id);
        $this->getJson('/api/registrar/document-request-activity?time_filter=today&limit=10')
            ->assertOk()
            ->assertJsonFragment(['request_id' => $insideToday->id, 'action' => 'approved'])
            ->assertJsonMissing(['request_id' => $insideYesterday->id]);
    }

    public function test_appointment_groups_stay_active_while_historical_records_remain_in_history(): void
    {
        $this->travelTo(Carbon::parse('2026-08-25 12:00:00'));
        $student = $this->createStudent('26-02002');
        $type = $this->createDocumentType('Certificate of Enrollment');
        $todayRequest = $this->createRequest($student, $type, 'processing', '2026-08-23 09:00:00', '2026-08-25 08:00:00');
        $upcomingRequest = $this->createRequest($student, $type, 'pending', '2026-08-24 09:00:00', '2026-08-25 09:00:00');
        $historicalRequest = $this->createRequest($student, $type, 'released', '2026-08-20 09:00:00', '2026-08-25 10:00:00');

        $today = $this->createAppointment($todayRequest, 'pending', '2026-08-25', '09:00', '2026-08-25 08:30:00');
        $upcoming = $this->createAppointment($upcomingRequest, 'confirmed', '2026-08-27', '10:00', '2026-08-25 09:30:00');
        $completed = $this->createAppointment($historicalRequest, 'completed', '2026-08-24', '11:00', '2026-08-25 10:30:00');
        $cancelled = $this->createAppointment($historicalRequest, 'cancelled', '2026-08-28', '13:00', '2026-08-25 11:30:00');

        Sanctum::actingAs($this->createRegistrar()->user);

        $this->getJson('/api/registrar/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id)
            ->assertJsonMissing(['id' => $completed->id])
            ->assertJsonMissing(['id' => $cancelled->id]);
        $this->getJson('/api/registrar/appointments?group=today&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $today->id);
        $this->getJson('/api/registrar/appointments?group=upcoming&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $upcoming->id);
        $this->getJson('/api/registrar/appointments?group=recent&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $reference = sprintf('REQ-%06d', $todayRequest->id);
        $this->getJson('/api/registrar/appointments?search='.urlencode($reference).'&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.document_request.request_reference', $reference);
        $this->getJson("/api/registrar/appointments?appointment_id={$upcoming->id}&page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $upcoming->id);

        $this->getJson('/api/registrar/document-requests/history?appointment_status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data.appointments.data')
            ->assertJsonPath('data.appointments.data.0.id', $completed->id);
        $this->getJson("/api/registrar/document-requests/history?appointment_id={$cancelled->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.appointments.data')
            ->assertJsonPath('data.appointments.data.0.id', $cancelled->id);
        $this->getJson('/api/registrar/document-requests/history?section=appointments&appointment_status=completed')
            ->assertOk()
            ->assertJsonPath('data.requests', null)
            ->assertJsonCount(1, 'data.appointments.data');
        $this->getJson('/api/registrar/document-requests/history?section=requests&request_status=released')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests.data')
            ->assertJsonPath('data.appointments', null);
    }

    public function test_page_omitted_appointment_array_is_limited_to_fifty_records(): void
    {
        $this->travelTo(Carbon::parse('2026-08-25 12:00:00'));
        $student = $this->createStudent('26-02005');
        $type = $this->createDocumentType('Certification');
        $documentRequest = $this->createRequest($student, $type, 'pending', '2026-08-25 08:00:00', '2026-08-25 08:00:00');

        foreach (range(0, 50) as $offset) {
            $date = Carbon::parse('2026-09-01')->addDays($offset)->toDateString();
            $this->createAppointment($documentRequest, 'pending', $date, '09:00', '2026-08-25 09:00:00');
        }

        Sanctum::actingAs($this->createRegistrar()->user);

        $this->getJson('/api/registrar/appointments')
            ->assertOk()
            ->assertJsonCount(50, 'data');
        $this->getJson('/api/registrar/appointments?page=1')
            ->assertOk()
            ->assertJsonCount(50, 'data.data')
            ->assertJsonPath('data.total', 51);
    }

    public function test_recent_activity_is_derived_from_workflow_timestamps_and_is_registrar_only(): void
    {
        $this->travelTo(Carbon::parse('2026-08-25 12:00:00'));
        $student = $this->createStudent('26-02003');
        $type = $this->createDocumentType('Good Moral Certificate');
        $documentRequest = $this->createRequest(
            $student,
            $type,
            'processing',
            '2026-08-23 09:00:00',
            '2026-08-25 09:00:00',
            ['approved_at' => '2026-08-25 08:00:00', 'processed_at' => '2026-08-25 09:00:00'],
        );
        $confirmed = $this->createAppointment($documentRequest, 'confirmed', '2026-08-26', '09:00', '2026-08-25 10:00:00');
        $completed = $this->createAppointment($documentRequest, 'completed', '2026-08-24', '10:00', '2026-08-25 11:00:00');

        Sanctum::actingAs($this->createRegistrar()->user);
        $response = $this->getJson('/api/registrar/document-request-activity?time_filter=today&limit=10')
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'request',
                'action' => 'approved',
                'request_id' => $documentRequest->id,
                'request_reference' => sprintf('REQ-%06d', $documentRequest->id),
                'destination' => 'requests',
            ])
            ->assertJsonFragment(['type' => 'request', 'action' => 'processed'])
            ->assertJsonFragment([
                'type' => 'appointment',
                'action' => 'confirmed',
                'appointment_id' => $confirmed->id,
                'destination' => 'appointments',
            ])
            ->assertJsonFragment([
                'type' => 'appointment',
                'action' => 'completed',
                'appointment_id' => $completed->id,
                'destination' => 'history',
            ])
            ->assertJsonFragment([
                'student_number' => '26-02003',
                'document_name' => 'Good Moral Certificate',
            ]);

        $occurredAt = $response->json('data.0.occurred_at');
        $this->assertIsString($occurredAt);
        $this->assertNotSame('', $occurredAt);
        $this->getJson('/api/registrar/document-request-activity?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->getJson('/api/registrar/document-request-activity')->assertForbidden();
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
            'email' => "{$number}@example.test",
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
            'email' => "registrar{$user->id}@example.test",
        ]);

        return RegistrarStaff::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'employee_number' => "REG-{$user->id}",
            'position' => 'Registrar Staff',
            'employment_status' => 'regular',
            'status' => 'active',
        ]);
    }

    private function createDocumentType(string $name): DocumentType
    {
        return DocumentType::create([
            'document_name' => $name,
            'processing_fee' => 0,
            'processing_days' => 1,
            'requires_appointment' => true,
            'status' => 'active',
        ]);
    }

    private function createRequest(
        Student $student,
        DocumentType $type,
        string $status,
        string $createdAt,
        string $updatedAt,
        array $workflowTimestamps = [],
    ): DocumentRequest {
        $documentRequest = DocumentRequest::create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'quantity' => 1,
            'total_fee' => 0,
            'status' => $status,
            'request_date' => substr($createdAt, 0, 10),
            ...$workflowTimestamps,
        ]);
        DB::table('document_requests')->where('id', $documentRequest->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        return $documentRequest->fresh();
    }

    private function createAppointment(
        DocumentRequest $documentRequest,
        string $status,
        string $date,
        string $time,
        string $updatedAt,
    ): Appointment {
        $active = in_array($status, ['pending', 'confirmed'], true);
        $appointment = Appointment::create([
            'student_id' => $documentRequest->student_id,
            'document_request_id' => $documentRequest->id,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'purpose' => 'Document request',
            'status' => $status,
            'active_slot_key' => $active ? "{$date} {$time}" : null,
        ]);
        DB::table('appointments')->where('id', $appointment->id)->update([
            'created_at' => '2026-08-23 09:00:00',
            'updated_at' => $updatedAt,
        ]);

        return $appointment->fresh();
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

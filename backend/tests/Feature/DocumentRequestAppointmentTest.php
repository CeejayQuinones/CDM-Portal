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
use App\Models\StudentDocument;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentRequestAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_request_own_document_but_admin_is_denied(): void
    {
        $student = $this->createStudent('26-01001');
        $type = DocumentType::create(['document_name' => 'Transcript of Records', 'processing_fee' => 150, 'processing_days' => 3, 'requires_appointment' => true, 'status' => 'active']);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/document-requests', ['document_type_id' => $type->id, 'quantity' => 2, 'purpose' => 'Employment'])
            ->assertCreated()->assertJsonPath('data.student_id', $student->id)->assertJsonPath('data.total_fee', 300);

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->getJson('/api/document-requests')->assertForbidden();
    }

    public function test_student_cannot_view_another_students_request_or_book_an_unavailable_slot(): void
    {
        $first = $this->createStudent('26-01002');
        $second = $this->createStudent('26-01003');
        $type = DocumentType::create(['document_name' => 'Certificate of Enrollment', 'processing_fee' => 50, 'processing_days' => 1, 'requires_appointment' => true, 'status' => 'active']);
        $firstRequest = DocumentRequest::create(['student_id' => $first->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 50, 'status' => 'pending', 'request_date' => today()]);
        $secondRequest = DocumentRequest::create(['student_id' => $second->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 50, 'status' => 'pending', 'request_date' => today()]);
        $tomorrow = today()->addDay()->toDateString();

        Sanctum::actingAs($first->user);
        $this->postJson("/api/document-requests/{$firstRequest->id}/appointments", ['appointment_date' => $tomorrow, 'appointment_time' => '09:00'])->assertCreated();
        $this->getJson('/api/appointment-overview')
            ->assertOk()
            ->assertJsonCount(0, 'data.requests_needing_appointment')
            ->assertJsonCount(1, 'data.appointments');

        Sanctum::actingAs($second->user);
        $this->getJson("/api/document-requests/{$firstRequest->id}")->assertForbidden();
        $this->postJson("/api/document-requests/{$secondRequest->id}/appointments", ['appointment_date' => $tomorrow, 'appointment_time' => '09:00'])->assertStatus(409);
    }

    public function test_registrar_can_process_but_admin_cannot_access_registrar_endpoints(): void
    {
        $student = $this->createStudent('26-01004');
        $type = DocumentType::create(['document_name' => 'Good Moral Certificate', 'processing_fee' => 25, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active']);
        $documentRequest = DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 25, 'status' => 'pending', 'request_date' => today()]);
        $supportingType = DocumentType::create(['document_name' => 'Birth Certificate', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'inactive']);
        StudentDocument::create(['student_id' => $student->id, 'document_type_id' => $supportingType->id, 'availability_status' => 'available', 'verification_status' => 'verified', 'submitted_date' => today()]);
        $registrar = $this->createRegistrar();

        Sanctum::actingAs($registrar->user);
        $this->getJson('/api/registrar/document-requests')->assertOk();
        $this->getJson('/api/registrar/appointments')->assertOk();
        $this->getJson("/api/registrar/document-requests/{$documentRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.student.user_profile.first_name', 'Student')
            ->assertJsonPath('data.student.student_number', '26-01004')
            ->assertJsonPath('data.student.course.course_code', 'BSIT')
            ->assertJsonPath('data.student.year_level', 1)
            ->assertJsonPath('data.student.student_status', 'regular')
            ->assertJsonPath('data.student.documents.0.document_type.document_name', 'Birth Certificate')
            ->assertJsonPath('data.student.documents.0.availability_status', 'available');
        $this->patchJson("/api/registrar/document-requests/{$documentRequest->id}", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'processing');

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->getJson('/api/registrar/document-requests')->assertForbidden();
    }

    public function test_registrar_releases_ready_document_directly_and_history_uses_existing_requests(): void
    {
        $this->assertFalse(Schema::hasColumn('document_requests', 'verification_code'));
        $this->assertFalse(Schema::hasColumn('document_requests', 'code_verified'));

        $student = $this->createStudent('26-01006');
        $type = DocumentType::create(['document_name' => 'Transcript of Records', 'processing_fee' => 150, 'processing_days' => 3, 'requires_appointment' => true, 'status' => 'active']);
        $documentRequest = DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 150, 'status' => 'pending', 'request_date' => today(), 'remarks' => 'Release to the student only.']);
        $cancelledRequest = DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 150, 'status' => 'cancelled', 'request_date' => today()->subDay()]);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $documentRequest->id, 'appointment_date' => today()->addDay(), 'appointment_time' => '10:00', 'purpose' => 'Document request', 'status' => 'confirmed', 'active_slot_key' => today()->addDay()->toDateString().' 10:00']);
        $registrar = $this->createRegistrar();

        Sanctum::actingAs($registrar->user);
        $this->patchJson("/api/registrar/document-requests/{$documentRequest->id}", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');
        $this->patchJson("/api/registrar/document-requests/{$documentRequest->id}", ['action' => 'ready_for_release'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_release');
        $this->patchJson("/api/registrar/document-requests/{$documentRequest->id}", ['action' => 'release'])
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonMissingPath('data.verification_code')
            ->assertJsonMissingPath('data.code_verified');

        $this->getJson('/api/registrar/document-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
        $this->getJson('/api/registrar/document-requests/history?search=Transcript')
            ->assertOk()
            ->assertJsonCount(2, 'data.requests.data')
            ->assertJsonFragment(['id' => $documentRequest->id, 'status' => 'released', 'remarks' => 'Release to the student only.'])
            ->assertJsonFragment(['id' => $cancelledRequest->id, 'status' => 'cancelled']);
        $this->getJson('/api/registrar/document-requests/history?request_status=released')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests.data')
            ->assertJsonPath('data.requests.data.0.latest_appointment.status', 'confirmed');
    }

    public function test_cancelled_and_completed_appointments_leave_active_list_and_remain_in_history(): void
    {
        $student = $this->createStudent('26-01007');
        $type = DocumentType::create(['document_name' => 'Certificate of Enrollment', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => true, 'status' => 'active']);
        $documentRequest = DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 0, 'status' => 'processing', 'request_date' => today(), 'remarks' => 'Request remark.']);
        $cancelledAppointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $documentRequest->id, 'appointment_date' => today()->addDay(), 'appointment_time' => '09:00', 'purpose' => 'Document request', 'status' => 'pending', 'active_slot_key' => today()->addDay()->toDateString().' 09:00']);
        $completedAppointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $documentRequest->id, 'appointment_date' => today()->addDays(2), 'appointment_time' => '10:00', 'purpose' => 'Document request', 'status' => 'confirmed', 'active_slot_key' => today()->addDays(2)->toDateString().' 10:00']);
        $registrar = $this->createRegistrar();

        Sanctum::actingAs($registrar->user);
        $this->getJson('/api/registrar/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->patchJson("/api/registrar/appointments/{$cancelledAppointment->id}", ['status' => 'cancelled', 'remarks' => 'Cancelled by Registrar Staff.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('appointments', ['id' => $cancelledAppointment->id, 'status' => 'cancelled', 'remarks' => 'Cancelled by Registrar Staff.']);
        $this->getJson('/api/registrar/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completedAppointment->id);
        $this->getJson('/api/registrar/document-requests/history?appointment_status=cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data.appointments.data')
            ->assertJsonPath('data.appointments.data.0.id', $cancelledAppointment->id)
            ->assertJsonPath('data.appointments.data.0.status', 'cancelled')
            ->assertJsonPath('data.appointments.data.0.document_request.status', 'processing')
            ->assertJsonPath('data.appointments.data.0.remarks', 'Cancelled by Registrar Staff.');

        $this->patchJson("/api/registrar/appointments/{$completedAppointment->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('appointments', 2);
        $this->getJson('/api/registrar/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/registrar/document-requests/history?appointment_status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data.appointments.data')
            ->assertJsonPath('data.appointments.data.0.id', $completedAppointment->id);
    }

    public function test_registrar_manages_document_types_while_students_only_see_active_types(): void
    {
        $registrar = $this->createRegistrar();
        Sanctum::actingAs($registrar->user);

        $this->getJson('/api/registrar/document-types')->assertOk();
        $created = $this->postJson('/api/registrar/document-types', [
            'document_name' => 'Certificate of Graduation',
            'description' => 'For graduating students.',
            'requires_appointment' => true,
            'processing_fee' => 75,
            'processing_days' => 2,
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data');

        $this->patchJson("/api/registrar/document-types/{$created['id']}", [
            'description' => 'Updated instructions.',
            'requires_appointment' => false,
        ])->assertOk()->assertJsonPath('data.requires_appointment', false);
        $this->patchJson("/api/registrar/document-types/{$created['id']}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $active = DocumentType::create(['document_name' => 'Certificate of Registration', 'processing_fee' => 50, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active']);
        $student = $this->createStudent('26-01005');
        Sanctum::actingAs($student->user);
        $this->getJson('/api/document-types')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->postJson('/api/document-requests', ['document_type_id' => $created['id'], 'quantity' => 1])->assertNotFound();
        $this->postJson('/api/registrar/document-types', ['document_name' => 'Not Allowed', 'requires_appointment' => false])->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->getJson('/api/registrar/document-types')->assertForbidden();
    }

    private function createStudent(string $number): Student
    {
        DB::table('departments')->insertOrIgnore(['id' => 1, 'department_code' => 'ICS', 'department_name' => 'Institute of Computer Studies', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->userWithRole(Role::STUDENT);
        $profile = UserProfile::create(['user_id' => $user->id, 'first_name' => 'Student', 'last_name' => str_replace('-', '', $number), 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'email' => "{$number}@example.test"]);
        $course = Course::firstOrCreate(['course_code' => 'BSIT'], ['department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active']);
        $curriculum = Curriculum::firstOrCreate(['curriculum_code' => 'BSIT-2026'], ['course_id' => $course->id, 'curriculum_name' => 'BSIT Curriculum', 'effective_year' => 2026, 'status' => 'active']);

        return Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $course->id, 'curriculum_id' => $curriculum->id, 'student_number' => $number, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular']);
    }

    private function createRegistrar(): RegistrarStaff
    {
        $user = $this->userWithRole(Role::REGISTRAR_STAFF);
        $profile = UserProfile::create(['user_id' => $user->id, 'first_name' => 'Registrar', 'last_name' => 'Staff', 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'email' => "registrar{$user->id}@example.test"]);

        return RegistrarStaff::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'employee_number' => "REG-{$user->id}", 'position' => 'Registrar Staff', 'employment_status' => 'regular', 'status' => 'active']);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

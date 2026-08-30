<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentBlockedDate;
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
        $availableDate = $this->futureWeekday();

        Sanctum::actingAs($first->user);
        $this->postJson("/api/document-requests/{$firstRequest->id}/appointments", ['appointment_date' => $availableDate, 'appointment_time' => '09:00'])->assertCreated();
        $this->getJson('/api/appointment-overview')
            ->assertOk()
            ->assertJsonCount(0, 'data.requests_needing_appointment')
            ->assertJsonCount(1, 'data.appointments');

        Sanctum::actingAs($second->user);
        $this->getJson("/api/document-requests/{$firstRequest->id}")->assertForbidden();
        $this->postJson("/api/document-requests/{$secondRequest->id}/appointments", ['appointment_date' => $availableDate, 'appointment_time' => '09:00'])->assertStatus(409);
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

    public function test_work_queues_have_independent_counts_and_pagination(): void
    {
        $student = $this->createStudent('26-01040');
        $type = DocumentType::create(['document_name' => 'Certificate of Enrollment', 'processing_fee' => 50, 'processing_days' => 1, 'requires_appointment' => true, 'status' => 'active']);

        foreach (range(1, 23) as $offset) {
            DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 50, 'status' => 'pending', 'request_date' => today()->subDays($offset)]);
        }
        foreach (range(1, 12) as $offset) {
            DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 50, 'status' => 'processing', 'request_date' => today()->subDays($offset), 'approved_at' => now()->subDays($offset)]);
        }
        DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 50, 'status' => 'ready_for_release', 'request_date' => today()]);

        Sanctum::actingAs($this->createRegistrar()->user);

        $this->getJson('/api/registrar/document-requests?view=work_queues&pending_page=2&processing_page=2')
            ->assertOk()
            ->assertJsonPath('data.pending.total', 23)
            ->assertJsonPath('data.pending.current_page', 2)
            ->assertJsonCount(3, 'data.pending.data')
            ->assertJsonPath('data.processing.total', 12)
            ->assertJsonPath('data.processing.current_page', 2)
            ->assertJsonCount(2, 'data.processing.data')
            ->assertJsonMissing(['status' => 'ready_for_release']);
    }

    public function test_request_moves_through_work_queues_release_area_and_history_with_invalid_transitions_rejected(): void
    {
        $student = $this->createStudent('26-01041');
        $type = DocumentType::create(['document_name' => 'Certificate of Enrollment', 'processing_fee' => 50, 'processing_days' => 1, 'requires_appointment' => true, 'status' => 'active']);

        Sanctum::actingAs($student->user);
        $documentRequestId = $this->postJson('/api/document-requests', [
            'document_type_id' => $type->id,
            'quantity' => 1,
            'purpose' => 'Employment',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        Sanctum::actingAs($this->createRegistrar()->user);
        $reference = sprintf('REQ-%06d', $documentRequestId);

        $this->getJson("/api/registrar/document-requests?view=work_queues&search={$reference}")
            ->assertOk()
            ->assertJsonPath('data.pending.total', 1)
            ->assertJsonPath('data.processing.total', 0);

        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'ready_for_release'])
            ->assertUnprocessable();

        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'release'])
            ->assertUnprocessable();

        $this->getJson("/api/registrar/document-requests?view=work_queues&search={$reference}")
            ->assertOk()
            ->assertJsonPath('data.pending.total', 0)
            ->assertJsonPath('data.processing.total', 1);

        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'ready_for_release'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_release');

        $this->getJson("/api/registrar/document-requests?view=work_queues&search={$reference}")
            ->assertOk()
            ->assertJsonPath('data.pending.total', 0)
            ->assertJsonPath('data.processing.total', 0);
        $this->getJson("/api/registrar/document-requests?status=ready_for_release&request_id={$documentRequestId}")
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'return_to_processing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", [
            'action' => 'return_to_processing',
            'reason' => 'Document preparation error: name needs correction.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.status_changes.0.action', 'returned_to_processing')
            ->assertJsonPath('data.status_changes.0.reason', 'Document preparation error: name needs correction.')
            ->assertJsonPath('data.status_changes.0.registrar_staff.user.profile.first_name', 'Registrar');
        $this->assertDatabaseHas('document_request_status_changes', [
            'document_request_id' => $documentRequestId,
            'from_status' => 'ready_for_release',
            'to_status' => 'processing',
            'action' => 'returned_to_processing',
            'reason' => 'Document preparation error: name needs correction.',
        ]);
        $this->getJson('/api/registrar/document-request-activity?limit=20')
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'returned_to_processing',
                'reason' => 'Document preparation error: name needs correction.',
            ]);

        $this->getJson("/api/registrar/document-requests?view=work_queues&search={$reference}")
            ->assertOk()
            ->assertJsonPath('data.pending.total', 0)
            ->assertJsonPath('data.processing.total', 1);
        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'ready_for_release'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_release');

        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", ['action' => 'release'])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->patchJson("/api/registrar/document-requests/{$documentRequestId}", [
            'action' => 'return_to_processing',
            'reason' => 'Attempted correction after release.',
        ])
            ->assertUnprocessable();

        $this->getJson("/api/registrar/document-requests/history?request_status=released&request_id={$documentRequestId}")
            ->assertOk()
            ->assertJsonPath('data.requests.total', 1)
            ->assertJsonPath('data.requests.data.0.status', 'released');
    }

    public function test_processing_rejection_and_cancellation_require_and_audit_a_reason(): void
    {
        $student = $this->createStudent('26-01042');
        $type = DocumentType::create(['document_name' => 'Form 137', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active']);
        $rejectedRequest = DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 0, 'status' => 'processing', 'request_date' => today()]);
        $cancelledRequest = DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $type->id, 'quantity' => 1, 'total_fee' => 0, 'status' => 'processing', 'request_date' => today()]);

        Sanctum::actingAs($this->createRegistrar()->user);

        $this->patchJson("/api/registrar/document-requests/{$rejectedRequest->id}", ['action' => 'reject'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->patchJson("/api/registrar/document-requests/{$rejectedRequest->id}", [
            'action' => 'reject',
            'reason' => 'Incorrect document requested.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->patchJson("/api/registrar/document-requests/{$cancelledRequest->id}", ['action' => 'cancel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->patchJson("/api/registrar/document-requests/{$cancelledRequest->id}", [
            'action' => 'cancel',
            'reason' => 'Student requested that processing stop.',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('document_request_status_changes', [
            'document_request_id' => $rejectedRequest->id,
            'from_status' => 'processing',
            'to_status' => 'rejected',
            'reason' => 'Incorrect document requested.',
        ]);
        $this->assertDatabaseHas('document_request_status_changes', [
            'document_request_id' => $cancelledRequest->id,
            'from_status' => 'processing',
            'to_status' => 'cancelled',
            'reason' => 'Student requested that processing stop.',
        ]);
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

    public function test_student_cannot_book_on_saturday(): void
    {
        $student = $this->createStudent('26-01101');
        $documentRequest = $this->createAppointmentRequest($student);
        $saturday = today()->next('Saturday')->toDateString();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/document-requests/{$documentRequest->id}/appointments", [
            'appointment_date' => $saturday,
            'appointment_time' => '09:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'This date is unavailable because appointments are closed on weekends.');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_student_cannot_book_on_sunday(): void
    {
        $student = $this->createStudent('26-01102');
        $documentRequest = $this->createAppointmentRequest($student);
        $sunday = today()->next('Sunday')->toDateString();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/document-requests/{$documentRequest->id}/appointments", [
            'appointment_date' => $sunday,
            'appointment_time' => '10:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'This date is unavailable because appointments are closed on weekends.');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_student_can_book_on_a_normal_valid_weekday(): void
    {
        $student = $this->createStudent('26-01103');
        $documentRequest = $this->createAppointmentRequest($student);
        $appointmentDate = $this->futureWeekday();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/document-requests/{$documentRequest->id}/appointments", [
            'appointment_date' => $appointmentDate,
            'appointment_time' => '10:00',
        ])->assertCreated();

        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame($appointmentDate, $appointment->appointment_date->toDateString());
        $this->assertDatabaseHas('appointments', [
            'document_request_id' => $documentRequest->id,
            'appointment_time' => '10:00',
        ]);
    }

    public function test_manually_crafted_request_cannot_book_an_active_blocked_date(): void
    {
        $student = $this->createStudent('26-01104');
        $documentRequest = $this->createAppointmentRequest($student);
        $blockedDate = $this->futureWeekday();
        AppointmentBlockedDate::create([
            'blocked_date' => $blockedDate,
            'type' => 'maintenance',
            'reason' => 'System Maintenance',
            'is_active' => true,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson("/api/document-requests/{$documentRequest->id}/appointments", [
            'appointment_date' => $blockedDate,
            'appointment_time' => '11:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'This date is unavailable due to System Maintenance.');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_inactive_block_does_not_prevent_student_booking(): void
    {
        $student = $this->createStudent('26-01105');
        $documentRequest = $this->createAppointmentRequest($student);
        $blockedDate = $this->futureWeekday();
        AppointmentBlockedDate::create([
            'blocked_date' => $blockedDate,
            'type' => 'office_closure',
            'reason' => 'Former Closure',
            'is_active' => false,
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson("/api/document-requests/{$documentRequest->id}/appointments", [
            'appointment_date' => $blockedDate,
            'appointment_time' => '11:00',
        ])->assertCreated();
    }

    public function test_registrar_can_create_edit_deactivate_and_delete_a_blocked_date(): void
    {
        $registrar = $this->createRegistrar();
        $blockedDate = $this->futureWeekday();
        Sanctum::actingAs($registrar->user);

        $created = $this->postJson('/api/registrar/appointment-blocked-dates', [
            'blocked_date' => $blockedDate,
            'type' => 'school_event',
            'reason' => 'Foundation Day',
        ])
            ->assertCreated()
            ->assertJsonPath('data.blocked_date', $blockedDate)
            ->assertJsonPath('data.is_active', true)
            ->json('data');

        $this->assertDatabaseHas('appointment_blocked_dates', [
            'id' => $created['id'],
            'created_by' => $registrar->user_id,
        ]);
        $this->getJson('/api/registrar/appointment-blocked-dates')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->postJson('/api/registrar/appointment-blocked-dates', [
            'blocked_date' => $blockedDate,
            'type' => 'school_event',
            'reason' => 'Foundation Day',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.blocked_date.0', 'An identical active block already exists for this date.');

        $this->patchJson("/api/registrar/appointment-blocked-dates/{$created['id']}", [
            'reason' => 'Updated Foundation Day',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.reason', 'Updated Foundation Day')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/registrar/appointment-blocked-dates/{$created['id']}")
            ->assertOk();
        $this->assertDatabaseMissing('appointment_blocked_dates', ['id' => $created['id']]);
    }

    public function test_registrar_can_load_and_update_weekend_availability_settings(): void
    {
        $registrar = $this->createRegistrar();
        Sanctum::actingAs($registrar->user);

        $this->getJson('/api/registrar/appointment-availability/settings')
            ->assertOk()
            ->assertJsonPath('data.block_saturday', true)
            ->assertJsonPath('data.block_sunday', true);

        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => false,
            'block_sunday' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.block_saturday', false)
            ->assertJsonPath('data.block_sunday', true);

        $this->assertDatabaseHas('appointment_availability_settings', [
            'id' => 1,
            'block_saturday' => false,
            'block_sunday' => true,
            'updated_by' => $registrar->user_id,
        ]);
    }

    public function test_student_and_admin_cannot_manage_blocked_dates(): void
    {
        $student = $this->createStudent('26-01106');
        Sanctum::actingAs($student->user);
        $this->getJson('/api/registrar/appointment-blocked-dates')->assertForbidden();
        $this->postJson('/api/registrar/appointment-blocked-dates', [
            'blocked_date' => $this->futureWeekday(),
            'type' => 'other',
            'reason' => 'Not allowed',
        ])->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->getJson('/api/registrar/appointment-blocked-dates')->assertForbidden();
    }

    public function test_student_availability_endpoint_is_month_scoped_and_excludes_inactive_blocks(): void
    {
        $student = $this->createStudent('26-01107');
        $activeDate = $this->futureWeekday();
        $month = substr($activeDate, 0, 7);
        AppointmentBlockedDate::create([
            'blocked_date' => $activeDate,
            'type' => 'holiday',
            'reason' => 'Active Holiday',
            'is_active' => true,
        ]);
        AppointmentBlockedDate::create([
            'blocked_date' => $this->futureWeekday(1),
            'type' => 'maintenance',
            'reason' => 'Inactive Maintenance',
            'is_active' => false,
        ]);
        AppointmentBlockedDate::create([
            'blocked_date' => today()->addMonths(2)->startOfMonth()->next('Monday'),
            'type' => 'other',
            'reason' => 'Outside Month',
            'is_active' => true,
        ]);

        Sanctum::actingAs($student->user);
        $this->getJson("/api/appointment-availability?month={$month}")
            ->assertOk()
            ->assertJsonPath('data.weekends_blocked', true)
            ->assertJsonCount(1, 'data.blocked_dates')
            ->assertJsonPath('data.blocked_dates.0.date', $activeDate)
            ->assertJsonPath('data.blocked_dates.0.type', 'holiday')
            ->assertJsonPath('data.blocked_dates.0.reason', 'Active Holiday')
            ->assertJsonMissing(['reason' => 'Inactive Maintenance'])
            ->assertJsonMissing(['reason' => 'Outside Month']);

        $this->getJson('/api/holidays')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', $activeDate)
            ->assertJsonPath('data.0.name', 'Active Holiday');
    }

    public function test_blocking_a_date_warns_about_active_appointments_without_modifying_them(): void
    {
        $student = $this->createStudent('26-01108');
        $documentRequest = $this->createAppointmentRequest($student);
        $blockedDate = $this->futureWeekday();
        $appointment = Appointment::create([
            'student_id' => $student->id,
            'document_request_id' => $documentRequest->id,
            'appointment_date' => $blockedDate,
            'appointment_time' => '14:00',
            'purpose' => 'Document request',
            'status' => 'confirmed',
            'active_slot_key' => $blockedDate.' 14:00',
        ]);

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->postJson('/api/registrar/appointment-blocked-dates', [
            'blocked_date' => $blockedDate,
            'type' => 'maintenance',
            'reason' => 'Emergency Maintenance',
        ])
            ->assertCreated()
            ->assertJsonPath('data.active_appointments_count', 1)
            ->assertJsonPath('message', 'Appointment date blocked successfully. This date currently has 1 active appointment.');

        $appointment->refresh();
        $this->assertSame($blockedDate, $appointment->appointment_date->toDateString());
        $this->assertSame('confirmed', $appointment->status);
        $this->assertSame($blockedDate.' 14:00', $appointment->active_slot_key);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_registrar_cannot_reschedule_an_appointment_onto_an_unavailable_date(): void
    {
        $student = $this->createStudent('26-01109');
        $documentRequest = $this->createAppointmentRequest($student);
        $originalDate = $this->futureWeekday();
        $blockedDate = $this->futureWeekday(1);
        $appointment = Appointment::create([
            'student_id' => $student->id,
            'document_request_id' => $documentRequest->id,
            'appointment_date' => $originalDate,
            'appointment_time' => '13:00',
            'purpose' => 'Document request',
            'status' => 'pending',
            'active_slot_key' => $originalDate.' 13:00',
        ]);
        AppointmentBlockedDate::create([
            'blocked_date' => $blockedDate,
            'type' => 'office_closure',
            'reason' => 'Registrar Office Closure',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->patchJson("/api/registrar/appointments/{$appointment->id}", [
            'appointment_date' => $blockedDate,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_date.0', 'This date is unavailable due to Registrar Office Closure.');

        $appointment->refresh();
        $this->assertSame($originalDate, $appointment->appointment_date->toDateString());
        $this->assertSame($originalDate.' 13:00', $appointment->active_slot_key);
    }

    private function futureWeekday(int $weeks = 0): string
    {
        return today()->next('Monday')->addWeeks($weeks)->toDateString();
    }

    private function createAppointmentRequest(Student $student): DocumentRequest
    {
        $type = DocumentType::create([
            'document_name' => 'Appointment Document',
            'processing_fee' => 50,
            'processing_days' => 1,
            'requires_appointment' => true,
            'status' => 'active',
        ]);

        return DocumentRequest::create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'quantity' => 1,
            'total_fee' => 50,
            'status' => 'pending',
            'request_date' => today(),
        ]);
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

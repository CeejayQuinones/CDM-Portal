<?php

namespace Tests\Feature;

use App\Mail\DocumentRequestApprovedMail;
use App\Models\Appointment;
use App\Models\AppointmentBlockedDate;
use App\Models\Cabinet;
use App\Models\CabinetSlot;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestBatch;
use App\Models\DocumentType;
use App\Models\PhysicalRetrievalTask;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentRequestAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_student_creates_pending_request_and_cannot_assign_or_verify(): void
    {
        $student = $this->createStudent('26-10001');
        Sanctum::actingAs($student->user);
        $response = $this->postJson('/api/document-requests', ['document_type_id' => $this->type()->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('document_requests', [
            'id' => $id,
            'purpose' => 'Scholarship',
            'purpose_details' => null,
        ]);
        $this->postJson("/api/registrar/document-requests/{$id}/appointment", ['appointment_date' => '2026-09-08'])->assertForbidden();
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '123456'])->assertForbidden();
        $this->postJson("/api/document-requests/{$id}/appointments", ['appointment_date' => '2026-09-08'])->assertNotFound();
    }

    public function test_first_request_of_each_document_is_free_each_manila_day_and_repeat_requests_are_charged(): void
    {
        $student = $this->createStudent('26-10011');
        $otherStudent = $this->createStudent('26-10012');
        $type = DocumentType::create([
            'document_name' => 'Good Moral Certificate', 'processing_fee' => 25, 'status' => 'active',
        ]);
        Sanctum::actingAs($student->user);

        $this->getJson('/api/document-types')->assertOk()->assertJsonPath('data.0.first_request_free_today', true);
        $first = $this->postJson('/api/document-requests', [
            'document_type_id' => $type->id, 'quantity' => 2, 'purpose_category' => 'Scholarship',
        ])->assertCreated()->assertJsonPath('data.total_fee', 0);
        $this->assertDatabaseHas('document_requests', ['id' => $first->json('data.id'), 'total_fee' => 0]);
        $this->assertSame('2026-09-07', DocumentRequest::findOrFail($first->json('data.id'))->request_date->toDateString());
        $this->getJson('/api/document-types')->assertOk()->assertJsonPath('data.0.first_request_free_today', false);
        $this->patchJson('/api/document-requests/'.$first->json('data.id').'/cancel', [
            'reason' => 'No longer needed',
        ])->assertOk();
        $this->postJson('/api/document-requests', [
            'document_type_id' => $type->id, 'quantity' => 3, 'purpose_category' => 'Evaluation',
        ])->assertCreated()->assertJsonPath('data.total_fee', 75);

        Sanctum::actingAs($otherStudent->user);
        $this->getJson('/api/document-types')->assertOk()->assertJsonPath('data.0.first_request_free_today', true);
        $this->postJson('/api/document-requests', [
            'document_type_id' => $type->id, 'purpose_category' => 'Scholarship',
        ])->assertCreated()->assertJsonPath('data.total_fee', 0);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 00:10:00', 'Asia/Manila'));
        Sanctum::actingAs($student->user);
        $this->getJson('/api/document-types')->assertOk()->assertJsonPath('data.0.first_request_free_today', true);
        $this->postJson('/api/document-requests', [
            'document_type_id' => $type->id, 'purpose_category' => 'Scholarship',
        ])->assertCreated()->assertJsonPath('data.total_fee', 0);
    }

    public function test_batch_requests_apply_the_same_daily_fee_rule_and_idempotent_retries_keep_the_original_fees(): void
    {
        $student = $this->createStudent('26-10013');
        $firstType = $this->type();
        $secondType = DocumentType::create([
            'document_name' => 'Certificate of Enrollment', 'processing_fee' => 50, 'status' => 'active',
        ]);
        Sanctum::actingAs($student->user);
        $firstBatch = [
            'idempotency_key' => '00a11200-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'items' => [
                ['document_type_id' => $firstType->id, 'quantity' => 2, 'purpose_category' => 'Scholarship'],
                ['document_type_id' => $secondType->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'],
            ],
        ];
        $response = $this->postJson('/api/document-request-batches', $firstBatch)->assertCreated();
        $this->assertEqualsCanonicalizing(['0.00', '0.00'], array_column($response->json('data.document_requests'), 'total_fee'));
        $this->postJson('/api/document-request-batches', $firstBatch)
            ->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseCount('document_requests', 2);

        $repeat = $this->postJson('/api/document-request-batches', [
            'idempotency_key' => '00a11200-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'items' => [
                ['document_type_id' => $firstType->id, 'quantity' => 2, 'purpose_category' => 'Evaluation'],
                ['document_type_id' => $secondType->id, 'quantity' => 3, 'purpose_category' => 'Evaluation'],
            ],
        ])->assertCreated();
        $this->assertEqualsCanonicalizing(['300.00', '150.00'], array_column($repeat->json('data.document_requests'), 'total_fee'));
        $this->postJson('/api/document-requests', [
            'document_type_id' => $firstType->id, 'purpose_category' => 'Scholarship',
        ])->assertCreated()->assertJsonPath('data.total_fee', 150);
    }

    public function test_unified_registrar_student_list_counts_only_active_documents_and_preserves_submission_identity(): void
    {
        $student = $this->createStudent('26-12001');
        $other = $this->createStudent('26-12002');
        $batch = DocumentRequestBatch::create(['student_id' => $student->id, 'idempotency_key' => '00a11200-7777-4777-8777-777777777777', 'payload_hash' => str_repeat('a', 64)]);
        $first = $this->requestFor($student);
        $first->update(['document_request_batch_id' => $batch->id]);
        $second = $this->requestFor($student);
        $second->update(['document_request_batch_id' => $batch->id, 'status' => 'approved']);
        $old = $this->requestFor($student);
        $old->update(['status' => 'completed']);
        $this->requestFor($other);

        $this->getJson('/api/registrar/document-request-students')->assertUnauthorized();
        Sanctum::actingAs($student->user);
        $this->getJson('/api/registrar/document-request-students')->assertForbidden();
        $this->getJson("/api/registrar/document-request-students/{$other->id}")->assertForbidden();
        $this->getJson("/api/registrar/document-request-students/{$other->id}/same-location")->assertForbidden();

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson('/api/registrar/document-request-students', ['X-CDM-Client' => 'web'])->assertForbidden();
        $this->getJson('/api/registrar/document-request-students?search=REQ-'.str_pad((string) $first->id, 6, '0', STR_PAD_LEFT))
            ->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.active_requests_count', 2)
            ->assertJsonPath('data.data.0.pending_requests_count', 1);
        $this->getJson('/api/registrar/document-request-students?status=approved')
            ->assertOk()->assertJsonPath('data.total', 1);
        $detail = $this->getJson("/api/registrar/document-request-students/{$student->id}")
            ->assertOk()->assertJsonPath('data.requests.total', 2)
            ->assertJsonPath('data.requests.data.0.batch.group_reference', $batch->group_reference);
        $this->assertStringNotContainsString('completed', $detail->getContent());
        $this->assertStringNotContainsString('verification_code_hash', $detail->getContent());
        $this->getJson('/api/registrar/document-request-students/999999')->assertNotFound();
    }

    public function test_same_location_lookup_matches_exact_drawer_and_distinct_active_students_without_writes(): void
    {
        $source = $this->createStudent('26-12101');
        $same = $this->createStudent('26-12102');
        $otherSlot = $this->createStudent('26-12103');
        $historical = $this->createStudent('26-12104');
        $cabinet = Cabinet::create(['cabinet_code' => 'LOOKUP', 'rows' => 1, 'columns' => 2]);
        $firstSlot = CabinetSlot::create(['cabinet_id' => $cabinet->id, 'slot_code' => 'L1']);
        $secondSlot = CabinetSlot::create(['cabinet_id' => $cabinet->id, 'slot_code' => 'L2']);
        foreach ([[$source, $firstSlot], [$same, $firstSlot], [$otherSlot, $secondSlot], [$historical, $firstSlot]] as [$student, $slot]) {
            StudentRecordLocation::create(['student_id' => $student->id, 'cabinet_slot_id' => $slot->id, 'assigned_at' => now()]);
        }
        $this->requestFor($source);
        $retrievalRequest = $this->requestFor($same);
        $this->requestFor($same);
        $retrievalTask = PhysicalRetrievalTask::create(['student_id' => $same->id, 'active_student_id' => $same->id, 'status' => 'awaiting']);
        $retrievalTask->documentRequests()->attach($retrievalRequest->id);
        $this->requestFor($otherSlot);
        $this->requestFor($historical)->update(['status' => 'cancelled']);
        $this->requestFor($historical)->update(['status' => 'completed']);
        $before = DocumentRequest::count();

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson("/api/registrar/document-request-students/{$source->id}/same-location")
            ->assertOk()->assertJsonPath('data.location.cabinet', 'LOOKUP')
            ->assertJsonPath('data.location.drawer', 'L1')
            ->assertJsonPath('data.students.total', 1)
            ->assertJsonPath('data.students.data.0.id', $same->id)
            ->assertJsonPath('data.students.data.0.active_requests_count', 2)
            ->assertJsonPath('data.students.data.0.retrieval_required_count', 1);
        $this->assertSame($before, DocumentRequest::count());
        $this->assertDatabaseCount('physical_retrieval_tasks', 1);
        $same->physicalRecordLocation->update(['cabinet_slot_id' => $secondSlot->id]);
        $this->getJson("/api/registrar/document-request-students/{$source->id}/same-location")
            ->assertOk()->assertJsonPath('data.students.total', 0);
    }

    public function test_unified_workspace_handles_missing_location_and_retrieved_task_without_active_requests(): void
    {
        $student = $this->createStudent('26-12201');
        $request = $this->requestFor($student);
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson("/api/registrar/document-request-students/{$student->id}/same-location")->assertUnprocessable();
        $this->getJson("/api/registrar/document-request-students/{$student->id}")
            ->assertOk()->assertJsonPath('data.student.physical_record_location', null);

        $request->update(['status' => 'completed']);
        $task = PhysicalRetrievalTask::create(['student_id' => $student->id, 'active_student_id' => $student->id, 'status' => 'retrieved', 'cabinet_code_snapshot' => 'A', 'slot_code_snapshot' => 'A1']);
        $this->getJson("/api/registrar/document-request-students/{$student->id}")
            ->assertOk()->assertJsonPath('data.requests.total', 0)
            ->assertJsonPath('data.retrieval_task.id', $task->id);
        $this->getJson("/api/registrar/document-request-students/{$student->id}/same-location")->assertNotFound();
    }

    public function test_unified_student_list_is_paginated_without_loading_all_active_requests(): void
    {
        for ($index = 1; $index <= 16; $index++) {
            $this->requestFor($this->createStudent(sprintf('26-123%02d', $index)));
        }
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson('/api/registrar/document-request-students')->assertOk()
            ->assertJsonPath('data.total', 16)->assertJsonCount(15, 'data.data');
        $this->getJson('/api/registrar/document-request-students?page=2')->assertOk()
            ->assertJsonPath('data.current_page', 2)->assertJsonCount(1, 'data.data');
    }

    public function test_student_submits_multiple_documents_atomically_and_retry_is_idempotent(): void
    {
        $student = $this->createStudent('26-11001');
        $first = $this->type();
        $second = DocumentType::create(['document_name' => 'Certificate of Enrollment', 'processing_fee' => 50, 'processing_days' => 2, 'status' => 'active']);
        Sanctum::actingAs($student->user);
        $payload = [
            'idempotency_key' => '00a11200-1111-4111-8111-111111111111',
            'items' => [
                ['document_type_id' => $first->id, 'quantity' => 2, 'purpose_category' => 'Scholarship'],
                ['document_type_id' => $second->id, 'quantity' => 1, 'purpose_category' => 'Others', 'purpose_details' => '  Enrollment verification  '],
            ],
        ];

        $response = $this->postJson('/api/document-request-batches', $payload)->assertCreated()
            ->assertJsonCount(2, 'data.document_requests');
        $this->assertStringNotContainsString('requires_physical_retrieval', $response->getContent());
        $batchId = $response->json('data.id');
        $this->assertSame('GROUP-'.str_pad((string) $batchId, 6, '0', STR_PAD_LEFT), $response->json('data.group_reference'));
        $this->assertDatabaseCount('document_requests', 2);
        $this->assertDatabaseHas('document_requests', ['document_request_batch_id' => $batchId, 'document_type_id' => $second->id, 'purpose_details' => 'Enrollment verification']);

        $this->postJson('/api/document-request-batches', $payload)->assertOk()->assertJsonPath('data.id', $batchId);
        $this->assertDatabaseCount('document_requests', 2);
        $payload['items'][0]['quantity'] = 3;
        $this->postJson('/api/document-request-batches', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('document_requests', 2);
        $this->getJson('/api/document-requests')->assertOk()->assertJsonPath('data.0.batch.group_reference', $response->json('data.group_reference'));
    }

    public function test_batch_rejects_duplicate_unavailable_and_invalid_items_without_partial_writes(): void
    {
        $student = $this->createStudent('26-11002');
        $first = $this->type();
        $inactive = DocumentType::create(['document_name' => 'Unavailable document', 'status' => 'inactive']);
        Sanctum::actingAs($student->user);
        $key = '00a11200-2222-4222-8222-222222222222';

        $this->postJson('/api/document-request-batches', ['idempotency_key' => $key, 'items' => [
            ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'],
            ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Evaluation'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.document_type_id');
        $this->postJson('/api/document-request-batches', ['idempotency_key' => $key, 'items' => [
            ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'],
            ['document_type_id' => $inactive->id, 'quantity' => 1, 'purpose_category' => 'Others', 'purpose_details' => ' '],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.purpose_details');
        $this->postJson('/api/document-request-batches', ['idempotency_key' => $key, 'items' => [
            ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Others'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.purpose_details');
        $this->postJson('/api/document-request-batches', ['idempotency_key' => $key, 'items' => [
            ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'],
            ['document_type_id' => $inactive->id, 'quantity' => 1, 'purpose_category' => 'Evaluation'],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('document_request_batches', 0);
        $this->assertDatabaseCount('document_requests', 0);
    }

    public function test_batch_rolls_back_all_documents_when_one_insert_fails(): void
    {
        $student = $this->createStudent('26-11006');
        $first = $this->type();
        $second = DocumentType::create(['document_name' => 'Enrollment Certificate', 'status' => 'active']);
        Sanctum::actingAs($student->user);
        DB::statement("CREATE TRIGGER fail_second_batch_item BEFORE INSERT ON document_requests WHEN NEW.document_type_id = {$second->id} BEGIN SELECT RAISE(ABORT, 'forced item failure'); END");
        $this->postJson('/api/document-request-batches', [
            'idempotency_key' => '00a11200-6666-4666-8666-666666666666',
            'items' => [
                ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'],
                ['document_type_id' => $second->id, 'quantity' => 1, 'purpose_category' => 'Evaluation'],
            ],
        ])->assertServerError();
        $this->assertDatabaseCount('document_requests', 0);
        $this->assertDatabaseCount('document_request_batches', 0);
        DB::statement('DROP TRIGGER fail_second_batch_item');
    }

    public function test_registrar_can_configure_retrieval_for_new_requests_only_and_queue_deduplicates_student_folder(): void
    {
        $student = $this->createStudent('26-11003');
        $first = $this->type();
        $second = DocumentType::create(['document_name' => 'Good Moral Copy', 'processing_fee' => 25, 'status' => 'active']);
        $cabinet = Cabinet::create(['cabinet_code' => 'QA', 'rows' => 1, 'columns' => 1]);
        $slot = CabinetSlot::create(['cabinet_id' => $cabinet->id, 'slot_code' => 'QA1']);
        StudentRecordLocation::create(['student_id' => $student->id, 'cabinet_slot_id' => $slot->id, 'assigned_at' => now()]);

        Sanctum::actingAs($student->user);
        $old = $this->postJson('/api/document-requests', ['document_type_id' => $first->id, 'purpose_category' => 'Scholarship'])->assertCreated();
        $this->assertDatabaseCount('physical_retrieval_tasks', 0);
        $this->getJson('/api/registrar/physical-retrieval')->assertForbidden();
        $this->patchJson("/api/registrar/document-types/{$first->id}", ['requires_physical_retrieval' => true])->assertForbidden();

        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-types/{$first->id}", ['requires_physical_retrieval' => true])->assertOk();
        $this->patchJson("/api/registrar/document-types/{$second->id}", ['requires_physical_retrieval' => true])->assertOk();
        $this->assertDatabaseCount('physical_retrieval_tasks', 0);

        Sanctum::actingAs($student->user);
        $batch = $this->postJson('/api/document-request-batches', [
            'idempotency_key' => '00a11200-3333-4333-8333-333333333333',
            'items' => [
                ['document_type_id' => $first->id, 'quantity' => 1, 'purpose_category' => 'Scholarship'],
                ['document_type_id' => $second->id, 'quantity' => 1, 'purpose_category' => 'Evaluation'],
            ],
        ])->assertCreated();
        $this->assertDatabaseCount('physical_retrieval_tasks', 1);
        $this->assertDatabaseCount('physical_retrieval_request', 2);
        $this->assertDatabaseMissing('physical_retrieval_request', ['document_request_id' => $old->json('data.id')]);
        $this->postJson('/api/document-requests', ['document_type_id' => $first->id, 'purpose_category' => 'Evaluation'])->assertCreated();
        $this->assertDatabaseCount('physical_retrieval_tasks', 1);
        $this->assertDatabaseCount('physical_retrieval_request', 3);

        Sanctum::actingAs($staff->user);
        $this->getJson('/api/registrar/document-request-batches?search='.$batch->json('data.group_reference'))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonCount(2, 'data.data.0.document_requests');
        $queue = $this->getJson('/api/registrar/physical-retrieval')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.student.physical_record_location.cabinet_slot.cabinet.cabinet_code', 'QA');
        $taskId = $queue->json('data.data.0.id');
        $this->getJson('/api/registrar/physical-retrieval')->assertOk();
        $this->assertDatabaseCount('physical_retrieval_events', 1);
        $this->patchJson("/api/registrar/physical-retrieval/{$taskId}", ['action' => 'retrieve'])
            ->assertOk()->assertJsonPath('data.status', 'retrieved');
        $this->patchJson("/api/registrar/physical-retrieval/{$taskId}", ['action' => 'retrieve'])->assertUnprocessable();
        $this->patchJson("/api/registrar/physical-retrieval/{$taskId}", ['action' => 'return'])
            ->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertDatabaseHas('physical_retrieval_tasks', ['id' => $taskId, 'cabinet_code_snapshot' => 'QA', 'slot_code_snapshot' => 'QA1', 'active_student_id' => null]);
        $this->assertDatabaseCount('physical_retrieval_events', 3);
        $this->postJson('/api/registrar/physical-retrieval/'.$taskId.'/return')->assertNotFound();
    }

    public function test_retrieval_without_location_is_visible_but_cannot_be_marked_retrieved(): void
    {
        $student = $this->createStudent('26-11004');
        $type = $this->type();
        $type->update(['requires_physical_retrieval' => true]);
        Sanctum::actingAs($student->user);
        $this->postJson('/api/document-requests', ['document_type_id' => $type->id, 'purpose_category' => 'Scholarship'])->assertCreated();
        $task = PhysicalRetrievalTask::firstOrFail();
        $this->getJson('/api/registrar/physical-retrieval')->assertForbidden();

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson('/api/registrar/physical-retrieval')->assertOk()->assertJsonPath('data.total', 1);
        $this->patchJson("/api/registrar/physical-retrieval/{$task->id}", ['action' => 'retrieve'])->assertUnprocessable();
        $this->assertSame('awaiting', $task->fresh()->status);
    }

    public function test_bulk_rejection_is_registrar_only_all_or_nothing_and_audited(): void
    {
        $student = $this->createStudent('26-11005');
        $first = $this->requestFor($student);
        $second = $this->requestFor($student);
        $endpoint = '/api/registrar/document-requests/bulk-reject';
        $payload = ['request_ids' => [$first->id, $second->id], 'reason' => 'Incomplete requirements'];

        Sanctum::actingAs($student->user);
        $this->postJson($endpoint, $payload)->assertForbidden();
        Sanctum::actingAs($this->createRegistrar()->user);
        $second->update(['status' => 'approved']);
        $this->postJson($endpoint, $payload)->assertUnprocessable();
        $this->assertSame('pending', $first->fresh()->status);
        $second->update(['status' => 'pending']);

        DB::statement("CREATE TRIGGER fail_second_bulk_rejection BEFORE UPDATE ON document_requests WHEN OLD.id = {$second->id} AND NEW.status = 'rejected' BEGIN SELECT RAISE(ABORT, 'forced bulk failure'); END");
        $this->postJson($endpoint, $payload)->assertServerError();
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertDatabaseMissing('document_request_status_changes', ['document_request_id' => $first->id, 'action' => 'rejected']);
        DB::statement('DROP TRIGGER fail_second_bulk_rejection');

        $this->postJson($endpoint, $payload)->assertOk()->assertJsonPath('data.request_ids.0', $first->id);
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertDatabaseHas('document_request_status_changes', ['document_request_id' => $first->id, 'action' => 'rejected']);
        $this->assertDatabaseHas('document_request_status_changes', ['document_request_id' => $second->id, 'action' => 'rejected']);
    }

    public function test_purpose_is_conditionally_validated_stored_and_visible_to_student_and_registrar(): void
    {
        $student = $this->createStudent('26-10019');
        $documentTypeId = $this->type()->id;
        Sanctum::actingAs($student->user);

        $this->postJson('/api/document-requests', ['document_type_id' => $documentTypeId, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose_category');
        $this->postJson('/api/document-requests', ['document_type_id' => $documentTypeId, 'purpose_category' => 'Employment'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose_category');
        $this->postJson('/api/document-requests', ['document_type_id' => $documentTypeId, 'purpose_category' => 'Others', 'purpose_details' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose_details');
        $this->postJson('/api/document-requests', ['document_type_id' => $documentTypeId, 'purpose_category' => 'Others', 'purpose_details' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose_details');

        $evaluation = $this->postJson('/api/document-requests', [
            'document_type_id' => $documentTypeId,
            'purpose_category' => 'Evaluation',
            'purpose_details' => 'must be discarded',
        ])->assertCreated();
        $this->assertDatabaseHas('document_requests', [
            'id' => $evaluation->json('data.id'),
            'purpose' => 'Evaluation',
            'purpose_details' => null,
        ]);

        $other = $this->postJson('/api/document-requests', [
            'document_type_id' => $documentTypeId,
            'purpose_category' => 'Others',
            'purpose_details' => '  Visa application evaluation  ',
        ])->assertCreated()
            ->assertJsonPath('data.purpose', 'Others')
            ->assertJsonPath('data.purpose_details', 'Visa application evaluation');
        $otherId = $other->json('data.id');

        $this->getJson('/api/document-requests')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $otherId,
                'purpose' => 'Others',
                'purpose_details' => 'Visa application evaluation',
            ]);

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson('/api/registrar/document-requests')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $otherId,
                'purpose' => 'Others',
                'purpose_details' => 'Visa application evaluation',
            ]);
        $this->getJson("/api/registrar/document-requests/{$otherId}")
            ->assertOk()
            ->assertJsonPath('data.purpose', 'Others')
            ->assertJsonPath('data.purpose_details', 'Visa application evaluation');
    }

    public function test_registrar_assigns_date_without_approving_and_invalid_dates_are_rejected(): void
    {
        $request = $this->requestFor($this->createStudent('26-10002'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-06'])->assertUnprocessable();
        AppointmentBlockedDate::create(['blocked_date' => '2026-09-08', 'type' => 'office_closure', 'reason' => 'Closed', 'is_active' => true]);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-08'])->assertUnprocessable();
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $appointment = Appointment::query()->where('document_request_id', $request->id)->firstOrFail();
        $this->assertSame('2026-09-09', $appointment->appointment_date->toDateString());
        $this->assertSame('pending', $appointment->status);
    }

    public function test_capacity_is_enforced_and_a_date_override_works(): void
    {
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        foreach (range(1, 5) as $index) {
            $request = $this->requestFor($this->createStudent("26-1010{$index}"));
            $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])->assertOk();
        }
        $sixth = $this->requestFor($this->createStudent('26-10106'));
        $this->postJson("/api/registrar/document-requests/{$sixth->id}/appointment", ['appointment_date' => '2026-09-09'])->assertUnprocessable();
        $this->putJson('/api/registrar/appointment-availability/capacity/2026-09-09', ['capacity' => 6])->assertOk();
        $this->postJson("/api/registrar/document-requests/{$sixth->id}/appointment", ['appointment_date' => '2026-09-09'])->assertOk();
    }

    public function test_window_choice_and_capacity_are_enforced_without_changing_daily_limit(): void
    {
        $staff = $this->createRegistrar();
        $type = DocumentType::create(['document_name' => 'Good Moral Certificate', 'processing_fee' => 0, 'status' => 'active']);
        $requests = collect(range(1, 3))->map(fn ($i) => DocumentRequest::create([
            'student_id' => $this->createStudent("26-1120{$i}")->id,
            'document_type_id' => $type->id,
            'quantity' => 1,
            'total_fee' => 0,
            'purpose' => 'Testing',
            'status' => 'pending',
            'request_date' => '2026-09-07',
        ]));
        Sanctum::actingAs($staff->user);
        $date = '2026-09-09';
        $this->getJson('/api/registrar/document-requests/'.$requests[0]->id)
            ->assertOk()->assertJsonPath('data.appointment_window_options', ['Window 1', 'Window 2']);
        $this->postJson('/api/registrar/document-requests/'.$requests[0]->id.'/appointment', ['appointment_date' => $date])->assertUnprocessable();
        $this->postJson('/api/registrar/document-requests/'.$requests[0]->id.'/appointment', ['appointment_date' => $date, 'service_window' => 'Window 4'])->assertUnprocessable();
        $this->putJson("/api/registrar/appointment-availability/capacity/{$date}/windows/Window%201", ['capacity' => 1])->assertOk();
        $this->postJson('/api/registrar/document-requests/'.$requests[0]->id.'/appointment', ['appointment_date' => $date, 'service_window' => 'Window 1'])->assertOk();
        $this->postJson('/api/registrar/document-requests/'.$requests[1]->id.'/appointment', ['appointment_date' => $date, 'service_window' => 'Window 1'])->assertUnprocessable();
        $this->postJson('/api/registrar/document-requests/'.$requests[1]->id.'/appointment', ['appointment_date' => $date, 'service_window' => 'Window 2'])->assertOk();
        $this->assertDatabaseHas('appointments', ['document_request_id' => $requests[1]->id, 'service_window' => 'Window 2']);
        $this->getJson('/api/registrar/appointment-availability/calendar?month=2026-09')->assertOk()
            ->assertJsonPath('data.days.8.windows.Window 1.booked', 1)
            ->assertJsonPath('data.days.8.windows.Window 1.capacity', 1)
            ->assertJsonPath('data.days.8.windows.Window 2.booked', 1);
        $this->postJson('/api/registrar/document-requests/'.$requests[2]->id.'/appointment', ['appointment_date' => $date, 'service_window' => 'Window 2'])->assertOk();
        $this->putJson("/api/registrar/appointment-availability/capacity/{$date}/windows/Window%202", ['capacity' => 1])->assertUnprocessable();
        $this->putJson("/api/registrar/appointment-availability/capacity/{$date}/windows/Window%202", ['capacity' => 2])->assertOk();
    }

    public function test_registrar_can_confirm_document_routing_and_existing_bookings_keep_their_window(): void
    {
        $staff = $this->createRegistrar();
        $request = $this->requestFor($this->createStudent('26-11301'));
        Sanctum::actingAs($staff->user);
        $this->patchJson('/api/registrar/document-types/'.$request->document_type_id, ['registrar_window' => 'Window 2'])
            ->assertOk()->assertJsonPath('data.registrar_window', 'Window 2');
        $this->assertDatabaseHas('document_types', ['id' => $request->document_type_id, 'registrar_window' => 'Window 2']);
        $this->getJson('/api/registrar/document-requests/'.$request->id)
            ->assertOk()->assertJsonPath('data.appointment_window_options', ['Window 2']);
        $this->postJson('/api/registrar/document-requests/'.$request->id.'/appointment', ['appointment_date' => '2026-09-09'])
            ->assertOk();
        $this->assertDatabaseHas('appointments', ['document_request_id' => $request->id, 'service_window' => 'Window 2']);
        $this->patchJson('/api/registrar/document-types/'.$request->document_type_id, ['registrar_window' => 'Window 3'])->assertOk();
        $this->assertDatabaseHas('appointments', ['document_request_id' => $request->id, 'service_window' => 'Window 2']);
        $this->postJson('/api/registrar/document-requests/'.$request->id.'/appointment', [
            'appointment_date' => '2026-09-10', 'service_window' => 'Window 2',
        ])->assertUnprocessable();
    }

    public function test_window_defaults_apply_to_future_dates_and_cannot_undercut_bookings(): void
    {
        $staff = $this->createRegistrar();
        $first = $this->requestFor($this->createStudent('26-11401'));
        $second = $this->requestFor($this->createStudent('26-11402'));
        $third = $this->requestFor($this->createStudent('26-11403'));
        Sanctum::actingAs($staff->user);
        $defaultUrl = '/api/registrar/appointment-availability/windows/Window%204/default';
        $this->putJson($defaultUrl, ['capacity' => 2])->assertOk();
        foreach ([$first, $second] as $request) {
            $this->postJson('/api/registrar/document-requests/'.$request->id.'/appointment', ['appointment_date' => '2026-09-09'])->assertOk();
        }
        $this->postJson('/api/registrar/document-requests/'.$third->id.'/appointment', ['appointment_date' => '2026-09-09'])->assertUnprocessable();
        $this->putJson($defaultUrl, ['capacity' => 1])->assertUnprocessable();
        $this->putJson('/api/registrar/appointment-availability/capacity/2026-09-09/windows/Window%204', ['capacity' => 2])->assertOk();
        $this->putJson($defaultUrl, ['capacity' => 1])->assertOk();
        $this->getJson('/api/registrar/appointment-availability/calendar?month=2026-09')->assertOk()
            ->assertJsonPath('data.window_defaults.Window 4', 1)
            ->assertJsonPath('data.days.8.windows.Window 4.capacity', 2)
            ->assertJsonPath('data.days.8.windows.Window 4.has_custom_capacity', true)
            ->assertJsonPath('data.days.9.windows.Window 4.capacity', 1);
    }

    public function test_approval_requires_appointment_hashes_code_and_does_not_expose_it(): void
    {
        Mail::fake();
        $request = $this->requestFor($this->createStudent('26-10003'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertUnprocessable();
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-07'])->assertOk();
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.verification_code_hash')->assertJsonMissingPath('data.verification_code_lookup');
        $this->assertDatabaseHas('appointments', ['document_request_id' => $request->id, 'status' => 'confirmed']);
        $this->assertNotNull($request->fresh()->verification_code_hash);
        Mail::assertSent(DocumentRequestApprovedMail::class);
    }

    public function test_claim_code_is_single_use_and_completion_is_atomic(): void
    {
        Mail::fake();
        $request = $this->requestFor($this->createStudent('26-10004'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-07'])->assertOk();
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertOk();
        $code = null;
        Mail::assertSent(DocumentRequestApprovedMail::class, function ($mail) use (&$code): bool {
            $code = $mail->claimCode;

            return true;
        });
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'complete'])->assertUnprocessable();
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertOk();
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'complete'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('appointments', ['document_request_id' => $request->id, 'status' => 'completed']);
    }

    public function test_rejection_releases_capacity_and_no_show_command_is_idempotent(): void
    {
        $request = $this->requestFor($this->createStudent('26-10005'));
        $staff = $this->createRegistrar();
        Appointment::create(['student_id' => $request->student_id, 'document_request_id' => $request->id, 'registrar_staff_id' => $staff->id, 'appointment_date' => '2026-09-04', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'pending']);
        Artisan::call('document-requests:cancel-no-shows');
        Artisan::call('document-requests:cancel-no-shows');
        $this->assertDatabaseHas('document_requests', ['id' => $request->id, 'status' => 'cancelled']);
        $this->assertSame(1, $request->statusChanges()->where('action', 'no_show_cancelled')->count());

        $second = $this->requestFor($this->createStudent('26-10006'));
        Appointment::create(['student_id' => $second->student_id, 'document_request_id' => $second->id, 'appointment_date' => '2026-09-09', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'pending']);
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-requests/{$second->id}", ['action' => 'reject', 'reason' => 'Incomplete supporting record'])->assertOk();
        $this->assertDatabaseHas('appointments', ['document_request_id' => $second->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('document_request_status_changes', [
            'document_request_id' => $second->id,
            'action' => 'rejected',
            'reason' => 'Incomplete supporting record',
        ]);
        Sanctum::actingAs($second->student->user);
        $this->getJson('/api/document-requests')->assertOk()
            ->assertJsonFragment(['id' => $second->id, 'status' => 'rejected', 'remarks' => 'Incomplete supporting record']);
        $this->patchJson("/api/registrar/document-requests/{$second->id}", ['action' => 'reject', 'reason' => 'Duplicate'])->assertForbidden();
    }

    public function test_admin_is_not_granted_registrar_workflow_permissions(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '123456'])->assertForbidden();
    }

    public function test_wrong_code_and_non_registrar_claim_actions_are_rejected(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10007'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($student->user);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertForbidden();
        $this->getJson("/api/document-requests/{$request->id}")->assertOk();

        Sanctum::actingAs($staff->user);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The verification code is invalid.');
    }

    public function test_holidays_are_present_in_test_schema_and_are_enforced(): void
    {
        $this->assertTrue(Schema::hasTable('holidays'));
        DB::table('holidays')->insert(['name' => 'College Foundation Day', 'holiday_date' => '2026-09-09', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $request = $this->requestFor($this->createStudent('26-10008'));
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This date is unavailable due to College Foundation Day.');
    }

    public function test_a_date_with_an_active_appointment_cannot_be_blocked_or_reactivated(): void
    {
        $request = $this->requestFor($this->createStudent('26-10121'));
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-09'])->assertOk();

        $block = ['blocked_date' => '2026-09-09', 'type' => 'office_closure', 'reason' => 'Registrar closure'];
        $this->postJson('/api/registrar/appointment-blocked-dates', $block)
            ->assertUnprocessable()->assertJsonValidationErrors('blocked_date');
        $this->assertDatabaseMissing('appointment_blocked_dates', $block);

        $inactive = AppointmentBlockedDate::create([...$block, 'is_active' => false]);
        $this->patchJson("/api/registrar/appointment-blocked-dates/{$inactive->id}", ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('blocked_date');
        $this->assertFalse($inactive->fresh()->is_active);

        $appointment = Appointment::query()->where('document_request_id', $request->id)->firstOrFail();
        $this->assertSame('pending', $appointment->status);
        $appointment->update(['status' => 'cancelled']);
        $this->patchJson("/api/registrar/appointment-blocked-dates/{$inactive->id}", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_weekend_cannot_be_closed_while_an_appointment_is_booked(): void
    {
        $request = $this->requestFor($this->createStudent('26-10122'));
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => false, 'block_sunday' => true,
        ])->assertOk();
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-12'])->assertOk();

        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => true, 'block_sunday' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('block_saturday');
        $this->getJson('/api/registrar/appointment-availability/settings')
            ->assertOk()->assertJsonPath('data.block_saturday', false);

        Appointment::query()->where('document_request_id', $request->id)->update(['status' => 'cancelled']);
        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => true, 'block_sunday' => true,
        ])->assertOk()->assertJsonPath('data.block_saturday', true);
    }

    public function test_holiday_preset_does_not_block_an_existing_booking_during_migration(): void
    {
        AppointmentBlockedDate::query()->whereDate('blocked_date', '2026-11-30')->where('type', 'holiday')->delete();
        $request = $this->requestFor($student = $this->createStudent('26-10123'));
        Appointment::create([
            'student_id' => $student->id,
            'document_request_id' => $request->id,
            'appointment_date' => '2026-11-30',
            'appointment_time' => '09:00',
            'purpose' => 'Document claim',
            'status' => 'pending',
        ]);

        $migration = require database_path('migrations/2026_11_16_000001_seed_appointment_holiday_defaults.php');
        $migration->up();

        $this->assertDatabaseHas('appointment_blocked_dates', [
            'blocked_date' => '2026-11-30',
            'type' => 'holiday',
            'is_active' => false,
        ]);
    }

    public function test_no_show_leaves_today_verified_and_terminal_requests_unchanged(): void
    {
        $student = $this->createStudent('26-10009');
        $todayRequest = $this->requestFor($student);
        $verifiedRequest = $this->requestFor($student);
        $terminalRequest = $this->requestFor($student);
        $verifiedRequest->update(['status' => 'approved', 'code_verified_at' => now()]);
        $terminalRequest->update(['status' => 'completed', 'completed_at' => now()]);

        Appointment::create(['student_id' => $student->id, 'document_request_id' => $todayRequest->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'pending']);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $verifiedRequest->id, 'appointment_date' => '2026-09-04', 'appointment_time' => '10:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $terminalRequest->id, 'appointment_date' => '2026-09-04', 'appointment_time' => '11:00', 'purpose' => 'Claim', 'status' => 'completed']);

        Artisan::call('document-requests:cancel-no-shows');
        $this->assertSame('pending', $todayRequest->fresh()->status);
        $this->assertSame('approved', $verifiedRequest->fresh()->status);
        $this->assertSame('completed', $terminalRequest->fresh()->status);
    }

    public function test_history_contains_terminal_states_and_student_results_are_scoped(): void
    {
        $student = $this->createStudent('26-10010');
        $other = $this->createStudent('26-10011');
        $completed = $this->requestFor($student);
        $rejected = $this->requestFor($student);
        $cancelled = $this->requestFor($student);
        $otherRequest = $this->requestFor($other);
        $completed->update(['status' => 'completed', 'completed_at' => now()]);
        $rejected->update(['status' => 'rejected', 'rejected_at' => now()]);
        $cancelled->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Sanctum::actingAs($this->createRegistrar()->user);
        $this->getJson('/api/registrar/document-requests/history?section=requests')
            ->assertOk()
            ->assertJsonFragment(['id' => $completed->id, 'status' => 'completed', 'request_date' => '2026-09-07T00:00:00.000000Z'])
            ->assertJsonFragment(['id' => $rejected->id, 'status' => 'rejected'])
            ->assertJsonFragment(['id' => $cancelled->id, 'status' => 'cancelled']);

        Sanctum::actingAs($student->user);
        $this->getJson('/api/document-requests')->assertOk()->assertJsonMissing(['id' => $otherRequest->id]);
        $this->getJson("/api/document-requests/{$otherRequest->id}")->assertForbidden();
    }

    public function test_verification_endpoint_throttles_brute_force_attempts(): void
    {
        Sanctum::actingAs($this->createRegistrar()->user);
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => str_pad((string) $attempt, 6, '0', STR_PAD_LEFT)])->assertUnprocessable();
        }
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => '999999'])->assertTooManyRequests();
    }

    public function test_verification_rejects_every_ineligible_request_and_appointment_state(): void
    {
        $code = '654321';
        $request = $this->requestFor($student = $this->createStudent('26-10012'));
        $request->update(['verification_code_lookup' => hash_hmac('sha256', $code, (string) config('app.key')), 'verification_code_hash' => Hash::make($code)]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($this->createRegistrar()->user);

        foreach (['pending', 'rejected', 'cancelled', 'completed'] as $status) {
            $request->update(['status' => $status, 'code_verified_at' => null]);
            $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        }

        $request->update(['status' => 'approved']);
        $appointment->update(['appointment_date' => '2026-09-08']);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $appointment->update(['appointment_date' => '2026-09-04']);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $appointment->update(['appointment_date' => '2026-09-07', 'status' => 'cancelled']);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
        $appointment->update(['status' => 'confirmed']);
        $request->update(['code_verified_at' => now()]);
        $this->postJson('/api/registrar/document-requests/verify-code', ['verification_code' => $code])->assertUnprocessable();
    }

    public function test_claim_secrets_are_absent_from_all_relevant_api_responses(): void
    {
        $code = '735291';
        $request = $this->requestFor($student = $this->createStudent('26-10013'));
        $request->update(['status' => 'approved', 'approved_at' => now(), 'verification_code_lookup' => hash_hmac('sha256', $code, (string) config('app.key')), 'verification_code_hash' => Hash::make($code)]);
        Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);

        Sanctum::actingAs($student->user);
        $responses = [
            $this->getJson('/api/document-requests')->assertOk(),
            $this->getJson("/api/document-requests/{$request->id}")->assertOk(),
        ];
        Sanctum::actingAs($this->createRegistrar()->user);
        $responses = [...$responses,
            $this->getJson('/api/registrar/document-requests')->assertOk(),
            $this->getJson("/api/registrar/document-requests/{$request->id}")->assertOk(),
            $this->getJson('/api/registrar/appointments')->assertOk(),
            $this->getJson('/api/registrar/document-requests/history')->assertOk(),
            $this->getJson('/api/registrar/dashboard')->assertOk(),
            $this->getJson('/api/registrar/document-request-activity')->assertOk(),
        ];
        foreach ($responses as $response) {
            $payload = $response->getContent();
            $this->assertStringNotContainsString('verification_code_hash', $payload);
            $this->assertStringNotContainsString('verification_code_lookup', $payload);
            $this->assertStringNotContainsString($code, $payload);
        }
    }

    public function test_failed_approval_email_can_be_recovered_with_a_new_registrar_only_code(): void
    {
        $request = $this->requestFor($this->createStudent('26-10014'));
        $staff = $this->createRegistrar();
        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/appointment", ['appointment_date' => '2026-09-07'])->assertOk();
        Mail::shouldReceive('to')->twice()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $oldLookup = $request->fresh()->verification_code_lookup;

        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")
            ->assertStatus(503)
            ->assertJsonMissingPath('verification_code_hash')
            ->assertJsonMissingPath('verification_code_lookup');
        $failedDeliveryLookup = $request->fresh()->verification_code_lookup;
        $this->assertNotSame($oldLookup, $failedDeliveryLookup);
        $this->assertSame('approved', $request->fresh()->status);

        Mail::swap(new MailManager($this->app));
        Mail::fake();
        $response = $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.verification_code_hash')->assertJsonMissingPath('data.verification_code_lookup');
        $this->assertNotSame($failedDeliveryLookup, $request->fresh()->verification_code_lookup);
        $this->assertNull($request->fresh()->code_verified_at);
        $this->assertDatabaseHas('document_request_status_changes', ['document_request_id' => $request->id, 'action' => 'claim_code_regenerated']);
        $this->assertStringNotContainsString('claimCode', $response->getContent());
        Mail::assertSent(DocumentRequestApprovedMail::class);

        Sanctum::actingAs($staff->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertOk();
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertTooManyRequests();
    }

    public function test_resend_is_denied_to_student_admin_verified_and_expired_requests(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10015'));
        $staff = $this->createRegistrar();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'verification_code_lookup' => hash('sha256', 'old'), 'verification_code_hash' => Hash::make('123456')]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($student->user);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertForbidden();
        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertForbidden();
        Sanctum::actingAs($staff->user);
        $request->update(['code_verified_at' => now()]);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertUnprocessable();
        $request->update(['code_verified_at' => null]);
        $appointment->update(['appointment_date' => '2026-09-04']);
        $this->postJson("/api/registrar/document-requests/{$request->id}/resend-claim-code")->assertUnprocessable();
    }

    public function test_verified_cancellation_is_atomic_audited_and_clears_claim_credentials(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10016'));
        $staff = $this->createRegistrar();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'code_verified_at' => now(), 'verification_code_lookup' => hash('sha256', 'old'), 'verification_code_hash' => Hash::make('123456')]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($staff->user);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'cancel', 'reason' => 'Identity could not be confirmed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $request->refresh();
        $this->assertNull($request->verification_code_lookup);
        $this->assertNull($request->verification_code_hash);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseHas('document_request_status_changes', ['document_request_id' => $request->id, 'action' => 'cancelled', 'reason' => 'Identity could not be confirmed']);
        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'approve'])->assertUnprocessable();
    }

    public function test_linked_appointment_patch_cannot_bypass_request_verification(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10017'));
        $request->update(['status' => 'approved', 'approved_at' => now()]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        Sanctum::actingAs($this->createRegistrar()->user);
        $this->patchJson("/api/registrar/appointments/{$appointment->id}", ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson("/api/registrar/appointments/{$appointment->id}", ['status' => 'cancelled', 'remarks' => 'Bypass attempt'])->assertUnprocessable();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_completion_rolls_back_request_when_appointment_update_fails(): void
    {
        $request = $this->requestFor($student = $this->createStudent('26-10018'));
        $request->update(['status' => 'approved', 'approved_at' => now(), 'code_verified_at' => now(), 'verification_code_lookup' => hash('sha256', 'old'), 'verification_code_hash' => Hash::make('123456')]);
        $appointment = Appointment::create(['student_id' => $student->id, 'document_request_id' => $request->id, 'appointment_date' => '2026-09-07', 'appointment_time' => '09:00', 'purpose' => 'Claim', 'status' => 'confirmed']);
        DB::statement("CREATE TRIGGER fail_document_appointment_completion BEFORE UPDATE ON appointments WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'forced completion failure'); END");
        Sanctum::actingAs($this->createRegistrar()->user);

        $this->patchJson("/api/registrar/document-requests/{$request->id}", ['action' => 'complete'])->assertServerError();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertNotNull($request->fresh()->verification_code_hash);
        $this->assertDatabaseMissing('document_request_status_changes', ['document_request_id' => $request->id, 'action' => 'completed']);
    }

    private function type(): DocumentType
    {
        return DocumentType::firstOrCreate(['document_name' => 'Transcript of Records'], ['processing_fee' => 150, 'processing_days' => 3, 'requires_appointment' => true, 'status' => 'active']);
    }

    private function requestFor(Student $student): DocumentRequest
    {
        return DocumentRequest::create(['student_id' => $student->id, 'document_type_id' => $this->type()->id, 'quantity' => 1, 'total_fee' => 150, 'purpose' => 'Testing', 'status' => 'pending', 'request_date' => '2026-09-07']);
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

<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\LargeDatasetCleanupSeeder;
use Database\Seeders\LargeDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhysicalRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_create_a_cabinet_with_predictable_slots_and_duplicates_are_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::REGISTRAR_STAFF));

        $this->postJson('/api/registrar/cabinets', [
            'cabinet_code' => 'a',
            'description' => 'Main student records cabinet.',
            'rows' => 2,
            'columns' => 3,
            'slot_capacity' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('data.cabinet_code', 'A')
            ->assertJsonPath('data.slots_count', 6)
            ->assertJsonPath('data.slots.0.slot_code', 'A1')
            ->assertJsonPath('data.slots.0.capacity', 10)
            ->assertJsonPath('data.slots.5.slot_code', 'A6');

        $this->assertDatabaseCount('cabinets', 1);
        $this->assertDatabaseCount('cabinet_slots', 6);

        $this->postJson('/api/registrar/cabinets', [
            'cabinet_code' => ' A ',
            'rows' => 1,
            'columns' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cabinet_code');
    }

    public function test_only_registrar_staff_can_manage_physical_records(): void
    {
        Sanctum::actingAs($this->userWithRole(Role::STUDENT));
        $this->getJson('/api/registrar/cabinets')->assertForbidden();
        $this->postJson('/api/registrar/cabinets', [
            'cabinet_code' => 'A',
            'rows' => 1,
            'columns' => 1,
        ])->assertForbidden();

        Sanctum::actingAs($this->userWithRole(Role::ADMIN));
        $this->getJson('/api/registrar/cabinets')->assertForbidden();
    }

    public function test_registrar_can_assign_and_change_a_student_location_and_profile_shows_it(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent('26-02001', 'John', 'Doe');
        $firstCabinet = $this->createCabinet('A', 2);
        $secondCabinet = $this->createCabinet('B', 1);
        $firstSlot = $firstCabinet->slots()->where('slot_code', 'A2')->firstOrFail();
        $secondSlot = $secondCabinet->slots()->firstOrFail();

        Sanctum::actingAs($registrar);

        $this->putJson("/api/registrar/students/{$student->id}/record-location", [
            'cabinet_slot_id' => $firstSlot->id,
            'remarks' => 'Original paper file.',
        ])
            ->assertOk()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.cabinet_slot.slot_code', 'A2')
            ->assertJsonPath('data.cabinet_slot.cabinet.cabinet_code', 'A');

        $this->patchJson("/api/registrar/students/{$student->id}/record-location", [
            'cabinet_slot_id' => $secondSlot->id,
            'remarks' => 'Moved during reorganization.',
        ])
            ->assertOk()
            ->assertJsonPath('data.cabinet_slot.slot_code', 'B1')
            ->assertJsonPath('data.remarks', 'Moved during reorganization.');

        $this->assertDatabaseCount('student_record_locations', 1);
        $this->assertDatabaseHas('student_record_locations', [
            'student_id' => $student->id,
            'cabinet_slot_id' => $secondSlot->id,
        ]);

        $this->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.physical_record_location.cabinet_slot.slot_code', 'B1')
            ->assertJsonPath('data.physical_record_location.cabinet_slot.cabinet.cabinet_code', 'B');
    }

    public function test_slot_detail_is_paginated_and_returns_students_with_existing_documents(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $cabinet = $this->createCabinet('A', 1);
        $slot = $cabinet->slots()->firstOrFail();
        $first = $this->createStudent('26-02002', 'Alice', 'Reyes');
        $second = $this->createStudent('26-02003', 'Bruno', 'Santos');
        $availableType = DocumentType::query()->create([
            'document_name' => 'Birth Certificate',
            'processing_fee' => 0,
            'processing_days' => 1,
            'requires_appointment' => false,
            'status' => 'inactive',
        ]);
        $missingType = DocumentType::query()->create([
            'document_name' => 'Good Moral Certificate',
            'processing_fee' => 0,
            'processing_days' => 1,
            'requires_appointment' => false,
            'status' => 'inactive',
        ]);

        foreach ([$first, $second] as $student) {
            StudentRecordLocation::query()->create([
                'student_id' => $student->id,
                'cabinet_slot_id' => $slot->id,
                'assigned_at' => now(),
            ]);
        }

        StudentDocument::query()->create([
            'student_id' => $first->id,
            'document_type_id' => $availableType->id,
            'availability_status' => 'available',
            'verification_status' => 'verified',
            'submitted_date' => today(),
        ]);
        StudentDocument::query()->create([
            'student_id' => $first->id,
            'document_type_id' => $missingType->id,
            'availability_status' => 'missing',
            'verification_status' => 'pending',
        ]);

        Sanctum::actingAs($registrar);

        $this->getJson("/api/registrar/cabinet-slots/{$slot->id}?per_page=1")
            ->assertOk()
            ->assertJsonPath('data.slot_code', 'A1')
            ->assertJsonPath('data.record_count', 2)
            ->assertJsonPath('data.students.total', 2)
            ->assertJsonCount(1, 'data.students.data')
            ->assertJsonPath('data.students.data.0.student_number', '26-02002')
            ->assertJsonPath('data.students.data.0.available_documents.0.name', 'Birth Certificate')
            ->assertJsonPath('data.students.data.0.missing_documents.0.name', 'Good Moral Certificate');
    }

    public function test_document_request_detail_includes_physical_record_location(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $student = $this->createStudent('26-02004', 'Carla', 'Cruz');
        $cabinet = $this->createCabinet('A', 2);
        $slot = $cabinet->slots()->where('slot_code', 'A2')->firstOrFail();
        StudentRecordLocation::query()->create([
            'student_id' => $student->id,
            'cabinet_slot_id' => $slot->id,
            'assigned_at' => now(),
        ]);
        $documentType = DocumentType::query()->create([
            'document_name' => 'Transcript of Records',
            'processing_fee' => 150,
            'processing_days' => 3,
            'requires_appointment' => true,
            'status' => 'active',
        ]);
        $documentRequest = DocumentRequest::query()->create([
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'quantity' => 1,
            'total_fee' => 150,
            'status' => 'pending',
            'request_date' => today(),
        ]);

        Sanctum::actingAs($registrar);

        $this->getJson("/api/registrar/document-requests/{$documentRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.student.physical_record_location.cabinet_slot.slot_code', 'A2')
            ->assertJsonPath('data.student.physical_record_location.cabinet_slot.cabinet.cabinet_code', 'A');
    }

    public function test_cabinet_summary_does_not_load_student_or_document_rows(): void
    {
        $registrar = $this->userWithRole(Role::REGISTRAR_STAFF);
        $cabinet = $this->createCabinet('A', 1);
        $student = $this->createStudent('26-02005', 'Donna', 'Lim');
        StudentRecordLocation::query()->create([
            'student_id' => $student->id,
            'cabinet_slot_id' => $cabinet->slots()->firstOrFail()->id,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($registrar);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/registrar/cabinets')
            ->assertOk()
            ->assertJsonPath('data.0.slots.0.record_count', 1);

        $queries = collect(DB::getQueryLog())->pluck('query')->map('strtolower');

        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, 'student_documents')));
        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, 'user_profiles')));
        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, ' from "students"')));
    }

    public function test_large_dataset_physical_record_plan_is_varied_balanced_and_within_capacity(): void
    {
        $plan = LargeDatasetSeeder::physicalRecordStressPlan();
        $occupancies = collect($plan['slot_occupancies']);

        $this->assertSame(8, $plan['cabinet_count']);
        $this->assertSame(['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'], $plan['cabinet_codes']);
        $this->assertSame(5, $plan['rows']);
        $this->assertSame(10, $plan['columns']);
        $this->assertSame(20, $plan['slot_capacity']);
        $this->assertSame(50, $plan['slots_per_cabinet']);
        $this->assertSame(400, $occupancies->count());
        $this->assertSame(5_000, $occupancies->sum());
        $this->assertSame(12.5, $occupancies->average());
        $this->assertSame(5, $occupancies->min());
        $this->assertSame(19, $occupancies->max());
        $this->assertSame([5 => 80, 13 => 240, 18 => 40, 19 => 40], $occupancies->countBy()->sortKeys()->all());
        $this->assertSame($plan, LargeDatasetSeeder::physicalRecordStressPlan());
        $this->assertGreaterThanOrEqual(4, $occupancies->unique()->count());
        $this->assertTrue($occupancies->chunk($plan['slots_per_cabinet'])->every(
            fn (Collection $cabinetOccupancies): bool => $cabinetOccupancies->sum() === 625,
        ));
        $this->assertTrue($occupancies->every(
            fn (int $occupancy): bool => $occupancy <= $plan['slot_capacity'],
        ));
    }

    public function test_large_dataset_cleanup_preserves_normal_cabinet_student_and_location(): void
    {
        $johnDoe = $this->createStudent('26-00001', 'John', 'Doe');
        $stressStudent = $this->createStudent('STRESS-00001', 'Stress', 'Student');
        $normalCabinet = $this->createCabinet('A', 2);
        $stressCabinet = Cabinet::query()->create([
            'cabinet_code' => 'B',
            'description' => 'Student paper records storage. '.LargeDatasetSeeder::PHYSICAL_RECORD_CABINET_MARKER.'.',
            'rows' => 1,
            'columns' => 1,
        ]);
        $stressSlot = $stressCabinet->slots()->create([
            'slot_code' => 'B1',
            'capacity' => 20,
        ]);
        $normalSlot = $normalCabinet->slots()->where('slot_code', 'A2')->firstOrFail();

        $johnLocation = StudentRecordLocation::query()->create([
            'student_id' => $johnDoe->id,
            'cabinet_slot_id' => $normalSlot->id,
            'assigned_at' => now(),
        ]);
        StudentRecordLocation::query()->create([
            'student_id' => $stressStudent->id,
            'cabinet_slot_id' => $stressSlot->id,
            'assigned_at' => now(),
            'remarks' => 'LargeDatasetSeeder physical record location.',
        ]);

        $this->seed(LargeDatasetCleanupSeeder::class);

        $this->assertDatabaseHas('cabinets', ['id' => $normalCabinet->id, 'cabinet_code' => 'A']);
        $this->assertDatabaseHas('cabinet_slots', ['id' => $normalSlot->id, 'slot_code' => 'A2']);
        $this->assertDatabaseHas('students', ['id' => $johnDoe->id, 'student_number' => '26-00001']);
        $this->assertDatabaseHas('student_record_locations', [
            'id' => $johnLocation->id,
            'student_id' => $johnDoe->id,
            'cabinet_slot_id' => $normalSlot->id,
        ]);
        $this->assertDatabaseMissing('cabinets', ['id' => $stressCabinet->id]);
        $this->assertDatabaseMissing('cabinet_slots', ['id' => $stressSlot->id]);
        $this->assertDatabaseMissing('students', ['id' => $stressStudent->id]);
    }

    public function test_large_dataset_cleanup_detects_a_cabinet_only_partial_run(): void
    {
        $normalCabinet = $this->createCabinet('A', 1);
        $stressCabinet = Cabinet::query()->create([
            'cabinet_code' => 'STRESS-CAB-08',
            'description' => 'LargeDatasetSeeder stress cabinet 08.',
            'rows' => 1,
            'columns' => 2,
        ]);
        $stressCabinet->slots()->createMany([
            ['slot_code' => 'STRESS-CAB-08-001', 'capacity' => 20],
            ['slot_code' => 'STRESS-CAB-08-002', 'capacity' => 20],
        ]);

        $this->seed(LargeDatasetCleanupSeeder::class);

        $this->assertDatabaseHas('cabinets', ['id' => $normalCabinet->id, 'cabinet_code' => 'A']);
        $this->assertDatabaseMissing('cabinets', ['id' => $stressCabinet->id]);
        $this->assertDatabaseCount('cabinet_slots', 1);
    }

    public function test_large_dataset_cleanup_preserves_an_unmarked_normal_lettered_cabinet(): void
    {
        $normalCabinet = Cabinet::query()->create([
            'cabinet_code' => 'B',
            'description' => 'Registrar-created student records cabinet.',
            'rows' => 1,
            'columns' => 1,
        ]);
        $normalSlot = $normalCabinet->slots()->create([
            'slot_code' => 'B1',
            'capacity' => 20,
        ]);

        $this->seed(LargeDatasetCleanupSeeder::class);

        $this->assertDatabaseHas('cabinets', [
            'id' => $normalCabinet->id,
            'cabinet_code' => 'B',
        ]);
        $this->assertDatabaseHas('cabinet_slots', [
            'id' => $normalSlot->id,
            'slot_code' => 'B1',
        ]);
    }

    private function createCabinet(string $code, int $slots): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'cabinet_code' => $code,
            'rows' => 1,
            'columns' => $slots,
        ]);

        for ($number = 1; $number <= $slots; $number++) {
            $cabinet->slots()->create([
                'slot_code' => $code.$number,
                'capacity' => 10,
            ]);
        }

        return $cabinet;
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
                'course_name' => 'Bachelor of Science in Information Technology',
                'years' => 4,
                'status' => 'active',
            ],
        );
        $curriculum = Curriculum::query()->firstOrCreate(
            ['curriculum_code' => 'BSIT-2026'],
            [
                'course_id' => $course->id,
                'curriculum_name' => 'BSIT Curriculum 2026',
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

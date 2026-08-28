<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CabinetSlotEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_rename_a_slot_without_recreating_it(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'slot_code' => 'Records Box 2',
        ]))
            ->assertOk()
            ->assertJsonPath('data.id', $slot->id)
            ->assertJsonPath('data.slot_code', 'Records Box 2');

        $this->assertDatabaseHas('cabinet_slots', [
            'id' => $slot->id,
            'slot_code' => 'Records Box 2',
        ]);
    }

    public function test_existing_student_assignments_remain_after_slot_rename(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $student = $this->createStudents(1)->first();
        $location = StudentRecordLocation::query()->create([
            'student_id' => $student->id,
            'cabinet_slot_id' => $slot->id,
            'assigned_at' => now(),
            'remarks' => 'Verified student record.',
        ]);
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'slot_code' => 'Records-02',
        ]))->assertOk();

        $this->assertDatabaseHas('student_record_locations', [
            'id' => $location->id,
            'student_id' => $student->id,
            'cabinet_slot_id' => $slot->id,
        ]);
    }

    public function test_registrar_can_change_slot_capacity_and_display_fields(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'capacity' => 30,
            'size' => 'wide',
            'description' => 'Current enrollment paper records.',
        ]))
            ->assertOk()
            ->assertJsonPath('data.capacity', 30)
            ->assertJsonPath('data.size', 'wide')
            ->assertJsonPath('data.description', 'Current enrollment paper records.');
    }

    public function test_capacity_cannot_be_reduced_below_current_occupancy(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $students = $this->createStudents(2);
        $students->each(fn (Student $student) => StudentRecordLocation::query()->create([
            'student_id' => $student->id,
            'cabinet_slot_id' => $slot->id,
            'assigned_at' => now(),
        ]));
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'capacity' => 1,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.capacity.0', 'This slot currently contains 2 records. Capacity cannot be reduced below 2.');

        $this->assertSame(20, $slot->fresh()->capacity);
    }

    public function test_invalid_visual_size_is_rejected(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'size' => 'custom-resize',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size']);
    }

    public function test_duplicate_slot_name_is_rejected_within_the_same_cabinet(): void
    {
        $cabinet = $this->createCabinetWithSlots();
        [$firstSlot, $secondSlot] = $cabinet->slots->values()->all();
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$secondSlot->id}", $this->slotPayload([
            'slot_code' => $firstSlot->slot_code,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slot_code']);
    }

    public function test_occupied_slot_cannot_be_disabled(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $student = $this->createStudents(1)->first();
        StudentRecordLocation::query()->create([
            'student_id' => $student->id,
            'cabinet_slot_id' => $slot->id,
            'assigned_at' => now(),
        ]);
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'status' => 'inactive',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Move the existing records before disabling this slot.');

        $this->assertSame('active', $slot->fresh()->status);
    }

    public function test_inactive_slot_is_excluded_and_rejected_as_a_bulk_move_destination(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $slot->update(['status' => 'inactive']);
        $student = $this->createStudents(1)->first();
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $optionsResponse = $this->getJson('/api/students/bulk-options')->assertOk();
        $destinationSlotIds = collect($optionsResponse->json('data.cabinets'))
            ->flatMap(fn (array $cabinet): array => $cabinet['slots'])
            ->pluck('id');

        $this->assertNotContains($slot->id, $destinationSlotIds);

        $moveResponse = $this->patchJson('/api/students/bulk', [
            'student_ids' => [$student->id],
            'action' => 'assign_record_location',
            'value' => ['cabinet_slot_id' => $slot->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['value.cabinet_slot_id']);

        $this->assertSame(
            'The selected cabinet slot is inactive. Choose an active slot.',
            $moveResponse->json('errors')['value.cabinet_slot_id'][0],
        );

        $this->assertDatabaseMissing('student_record_locations', ['student_id' => $student->id]);
    }

    public function test_student_cannot_edit_a_cabinet_slot(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $this->signIn(Role::STUDENT);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'slot_code' => 'Unauthorized Rename',
        ]))->assertForbidden();

        $this->assertNotSame('Unauthorized Rename', $slot->fresh()->slot_code);
    }

    public function test_step_up_is_required_to_edit_a_cabinet_slot(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $this->signIn(Role::REGISTRAR_STAFF);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'slot_code' => 'Protected Rename',
        ]))
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->assertNotSame('Protected Rename', $slot->fresh()->slot_code);
    }

    public function test_bulk_move_still_works_after_slot_edits(): void
    {
        $slot = $this->createCabinetWithSlots()->slots->first();
        $students = $this->createStudents(2);
        $this->signIn(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson("/api/registrar/cabinet-slots/{$slot->id}", $this->slotPayload([
            'slot_code' => 'Records Box 2',
            'capacity' => 2,
            'size' => 'large',
        ]))->assertOk();

        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'assign_record_location',
            'value' => ['cabinet_slot_id' => $slot->id],
        ])->assertOk()->assertJsonPath('data.updated_count', 2);

        $this->assertSame(2, StudentRecordLocation::query()->where('cabinet_slot_id', $slot->id)->count());
        $this->assertSame('Records Box 2', $slot->fresh()->slot_code);
    }

    /** @param array<string, mixed> $overrides */
    private function slotPayload(array $overrides = []): array
    {
        return [
            'slot_code' => 'A1',
            'capacity' => 20,
            'size' => 'small',
            'description' => null,
            'status' => 'active',
            ...$overrides,
        ];
    }

    private function createCabinetWithSlots(): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'cabinet_code' => 'A',
            'description' => 'Student paper records storage',
            'rows' => 1,
            'columns' => 2,
        ]);
        $cabinet->slots()->createMany([
            ['slot_code' => 'A1', 'capacity' => 20],
            ['slot_code' => 'A2', 'capacity' => 20],
        ]);

        return $cabinet->load('slots');
    }

    /** @return Collection<int, Student> */
    private function createStudents(int $count): Collection
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1,
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
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

        return collect(range(1, $count))->map(function (int $number) use ($course, $curriculum): Student {
            $user = $this->userWithRole(Role::STUDENT);
            $profile = UserProfile::query()->create([
                'user_id' => $user->id,
                'first_name' => "Student{$number}",
                'last_name' => 'Records',
                'gender' => 'Prefer not to say',
                'nationality' => 'Filipino',
                'email' => "slot.student{$number}@cdm.edu.ph",
            ]);

            return Student::query()->create([
                'user_id' => $user->id,
                'user_profile_id' => $profile->id,
                'course_id' => $course->id,
                'curriculum_id' => $curriculum->id,
                'student_number' => sprintf('26-%05d', $number),
                'admission_date' => '2026-08-01',
                'year_level' => 1,
                'student_status' => 'regular',
            ]);
        });
    }

    private function signIn(string $roleName, bool $stepUp = false): User
    {
        $user = $this->userWithRole($roleName);
        Sanctum::actingAs($user);

        if ($stepUp) {
            $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        }

        return $user;
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

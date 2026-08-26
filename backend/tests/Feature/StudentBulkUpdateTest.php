<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSlot;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_bulk_update_an_allowed_field_after_step_up(): void
    {
        $students = $this->createStudents(3);
        $this->signInAs(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'change_status',
            'value' => 'graduated',
        ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 3)
            ->assertJsonPath('data.action', 'change_status')
            ->assertJsonPath('message', '3 student records updated successfully.');

        $this->assertSame(3, Student::query()->where('student_status', 'graduated')->count());
    }

    public function test_student_cannot_use_the_bulk_endpoint(): void
    {
        $student = $this->createStudents(1)->first();
        $this->signInAs(Role::STUDENT);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => [$student->id],
            'action' => 'change_status',
            'value' => 'graduated',
        ])->assertForbidden();

        $this->assertSame('regular', $student->fresh()->student_status);
    }

    public function test_admin_follows_existing_student_management_rbac(): void
    {
        $student = $this->createStudents(1)->first();
        $this->signInAs(Role::ADMIN);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => [$student->id],
            'action' => 'change_status',
            'value' => 'irregular',
        ])->assertOk();

        $this->assertSame('irregular', $student->fresh()->student_status);
    }

    public function test_registrar_step_up_is_required_before_any_bulk_change(): void
    {
        $student = $this->createStudents(1)->first();
        $this->signInAs(Role::REGISTRAR_STAFF);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => [$student->id],
            'action' => 'change_year_level',
            'value' => 4,
        ])
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->assertSame(1, $student->fresh()->year_level);
    }

    public function test_invalid_student_ids_are_rejected_without_partial_updates(): void
    {
        $student = $this->createStudents(1)->first();
        $this->signInAs(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => [$student->id, 999999],
            'action' => 'change_status',
            'value' => 'dropped',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_ids.1']);

        $this->assertSame('regular', $student->fresh()->student_status);
    }

    public function test_arbitrary_database_columns_cannot_be_bulk_updated(): void
    {
        $student = $this->createStudents(1)->first();
        $this->signInAs(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => [$student->id],
            'action' => 'update_column',
            'column' => 'student_number',
            'value' => 'UNSAFE-NUMBER',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        $this->assertNotSame('UNSAFE-NUMBER', $student->fresh()->student_number);
    }

    public function test_bulk_year_level_and_course_updates_use_validated_values(): void
    {
        $students = $this->createStudents(2);
        $newCourse = $this->createCourse('BSCS');
        $this->signInAs(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'change_year_level',
            'value' => 3,
        ])->assertOk();

        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'change_course',
            'value' => $newCourse->id,
        ])->assertOk();

        $this->assertSame(2, Student::query()->where('year_level', 3)->count());
        $this->assertSame(2, Student::query()->where('course_id', $newCourse->id)->count());
    }

    public function test_bulk_cabinet_assignment_respects_capacity_and_is_atomic(): void
    {
        $students = $this->createStudents(2);
        $slot = $this->createCabinetSlot(1);
        $this->signInAs(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'assign_record_location',
            'value' => ['cabinet_slot_id' => $slot->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value.cabinet_slot_id']);

        $this->assertDatabaseCount('student_record_locations', 0);

        $slot->update(['capacity' => 2]);
        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'assign_record_location',
            'value' => ['cabinet_slot_id' => $slot->id],
        ])->assertOk()->assertJsonPath('data.updated_count', 2);

        $this->assertSame(2, StudentRecordLocation::query()->where('cabinet_slot_id', $slot->id)->count());
    }

    public function test_bulk_document_availability_reports_missing_documents_and_rolls_back(): void
    {
        $students = $this->createStudents(2);
        $documentType = DocumentType::query()->create([
            'document_name' => 'Form 137',
            'processing_fee' => 0,
            'processing_days' => 1,
            'requires_appointment' => false,
            'status' => 'active',
        ]);
        $document = StudentDocument::query()->create([
            'student_id' => $students->first()->id,
            'document_type_id' => $documentType->id,
            'verification_status' => 'verified',
            'availability_status' => 'available',
        ]);
        $this->signInAs(Role::REGISTRAR_STAFF, stepUp: true);

        $this->patchJson('/api/students/bulk', [
            'student_ids' => $students->pluck('id')->all(),
            'action' => 'update_document_availability',
            'value' => [
                'document_type_id' => $documentType->id,
                'availability_status' => 'missing',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_ids']);

        $this->assertSame('available', $document->fresh()->availability_status);
    }

    public function test_read_only_student_listing_is_unchanged_by_bulk_support(): void
    {
        $student = $this->createStudents(1)->first();
        $this->signInAs(Role::REGISTRAR_STAFF);

        $this->getJson('/api/students?search=Student1&year_level=1&student_status=regular')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('meta.total', 1);
    }

    private function signInAs(string $roleName, bool $stepUp = false): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
        $user = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
        Sanctum::actingAs($user);

        if ($stepUp) {
            $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        }

        return $user;
    }

    /** @return Collection<int, Student> */
    private function createStudents(int $count)
    {
        $course = $this->createCourse('BSIT');
        $curriculum = Curriculum::query()->create([
            'course_id' => $course->id,
            'curriculum_code' => 'BSIT-2026',
            'curriculum_name' => 'BSIT Curriculum 2026',
            'effective_year' => 2026,
            'status' => 'active',
        ]);
        $studentRole = Role::query()->firstOrCreate(['role_name' => Role::STUDENT], ['description' => Role::STUDENT]);

        return collect(range(1, $count))->map(function (int $number) use ($course, $curriculum, $studentRole): Student {
            $user = User::factory()->create(['role_id' => $studentRole->id, 'status' => 'active']);
            $profile = UserProfile::query()->create([
                'user_id' => $user->id,
                'first_name' => "Student{$number}",
                'last_name' => 'Records',
                'gender' => 'Prefer not to say',
                'nationality' => 'Filipino',
                'email' => "student{$number}@cdm.edu.ph",
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

    private function createCourse(string $code): Course
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1,
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Course::query()->create([
            'department_id' => 1,
            'course_code' => $code,
            'course_name' => "Bachelor of Science in {$code}",
            'years' => 4,
            'status' => 'active',
        ]);
    }

    private function createCabinetSlot(int $capacity): CabinetSlot
    {
        $cabinet = Cabinet::query()->create([
            'cabinet_code' => 'A-',
            'description' => 'Student paper records storage',
            'rows' => 1,
            'columns' => 1,
        ]);

        return CabinetSlot::query()->create([
            'cabinet_id' => $cabinet->id,
            'slot_code' => 'A-1',
            'capacity' => $capacity,
        ]);
    }
}

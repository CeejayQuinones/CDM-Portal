<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRecordAuditEvent;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentRecordProductivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_quick_search_combined_names_and_opening_a_record_adds_only_their_recent_view(): void
    {
        $student = $this->student('26-40001', 'Maria', 'Luz', 'Santos');
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $admin = $this->user(Role::ADMIN);

        Sanctum::actingAs($registrar);
        $this->getJson('/api/students/quick-search?q=Maria%20Santos')
            ->assertOk()
            ->assertJsonPath('data.results.0.student_number', '26-40001')
            ->assertJsonPath('data.results.0.name', 'Maria Luz Santos');
        $this->getJson('/api/students/quick-search?q=26-40001')
            ->assertOk()->assertJsonPath('data.exact', true);
        $this->getJson("/api/students/{$student->id}")->assertOk();
        $this->getJson('/api/student-records/productivity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recent_students.0.id', $student->id)
            ->assertJsonStructure(['data' => [
                'recent_students',
                'saved_filters',
                'visible_columns',
                'attention' => ['total', 'students'],
                'today' => ['students_viewed', 'records_updated', 'physical_records_moved', 'documents_updated'],
            ]]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/student-records/productivity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recent_students', [])
            ->assertJsonStructure(['data' => ['attention', 'today', 'saved_filters', 'visible_columns']]);
    }

    public function test_saved_filters_and_column_preferences_are_validated_and_owned_per_staff_user(): void
    {
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        Sanctum::actingAs($registrar);
        $filter = $this->postJson('/api/student-records/saved-filters', [
            'name' => 'Missing location',
            'filters' => ['attention' => 'physical_record', 'sort' => 'name'],
        ])->assertCreated()->json('data');

        $this->postJson('/api/student-records/saved-filters', [
            'name' => 'Missing location',
            'filters' => ['attention' => 'documents'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/api/student-records/preferences', [
            'visible_columns' => ['student_number', 'name', 'program', 'physical_record_status'],
        ])->assertOk()->assertJsonPath('data.visible_columns.3', 'physical_record_status');
        $this->putJson('/api/student-records/preferences', [
            'visible_columns' => ['student_number', 'name', 'password'],
        ])->assertUnprocessable()->assertJsonValidationErrors('visible_columns.2');
        $this->putJson('/api/student-records/preferences', [
            'visible_columns' => ['program', 'year_level'],
        ])->assertUnprocessable()->assertJsonValidationErrors('visible_columns');

        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->putJson("/api/student-records/saved-filters/{$filter['id']}", [
            'name' => 'Changed', 'filters' => ['attention' => 'any'],
        ])->assertNotFound();
        $this->deleteJson("/api/student-records/saved-filters/{$filter['id']}")->assertNotFound();
    }

    public function test_completeness_duplicate_warning_attention_filter_and_physical_locator_are_read_only(): void
    {
        $student = $this->student('26-40002', 'Ana', null, 'Reyes', '2007-01-01');
        $duplicate = $this->student('26-40003', 'Ana', null, 'Reyes', '2007-01-01');
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $cabinet = Cabinet::query()->create(['cabinet_code' => 'SR-1', 'rows' => 1, 'columns' => 1]);
        $slot = $cabinet->slots()->create(['slot_code' => 'A1', 'capacity' => 20]);
        StudentRecordLocation::query()->create(['student_id' => $student->id, 'cabinet_slot_id' => $slot->id, 'assigned_at' => now()]);
        StudentRecordAuditEvent::query()->create([
            'student_id' => $student->id,
            'actor_user_id' => $registrar->id,
            'action' => 'physical_record_location_changed',
            'metadata' => ['cabinet_slot_id' => $slot->id],
            'created_at' => now(),
        ]);
        Sanctum::actingAs($registrar);

        $this->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.record_completeness.status', 'needs_attention')
            ->assertJsonPath('data.duplicate_warnings.0.student_id', $duplicate->id)
            ->assertJsonPath('data.physical_record_locator.availability', 'available')
            ->assertJsonPath('data.physical_record_locator.cabinet', 'SR-1')
            ->assertJsonPath('data.physical_record_locator.slot', 'A1');
        $this->getJson('/api/students?attention=physical_record')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $duplicate->id);
        $this->assertDatabaseCount('students', 2);
    }

    public function test_non_staff_cannot_access_productivity_or_quick_search(): void
    {
        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/students/quick-search?q=sample')->assertForbidden();
            $this->getJson('/api/student-records/productivity')->assertForbidden();
        }
    }

    private function student(string $number, string $first, ?string $middle, string $last, ?string $birthDate = null): Student
    {
        DB::table('departments')->insertOrIgnore(['id' => 1, 'department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->user(Role::STUDENT);
        $profile = UserProfile::query()->create([
            'user_id' => $user->id, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last,
            'birth_date' => $birthDate, 'gender' => 'Prefer not to say', 'nationality' => 'Filipino',
            'email' => strtolower($first).$user->id.'@example.test',
        ]);
        $course = Course::query()->firstOrCreate(['course_code' => 'BSIT'], ['department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active']);
        $curriculum = Curriculum::query()->firstOrCreate(['curriculum_code' => 'BSIT-2026'], ['course_id' => $course->id, 'curriculum_name' => 'BSIT 2026', 'effective_year' => 2026, 'status' => 'active']);

        return Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $course->id, 'curriculum_id' => $curriculum->id, 'student_number' => $number, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular']);
    }

    private function user(string $role): User
    {
        $roleModel = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::factory()->create(['role_id' => $roleModel->id, 'status' => 'active']);
    }
}

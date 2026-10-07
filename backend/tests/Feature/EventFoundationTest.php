<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_admin_coordinator_can_create_edit_publish_cancel_and_archive_with_audit(): void
    {
        foreach ([Role::ADMIN] as $role) {
            Sanctum::actingAs($this->user($role));
            $created = $this->postJson('/api/events', $this->payload())->assertCreated()->assertJsonPath('data.managed_status', 'draft')->json('data');
            $updated = $this->putJson('/api/events/'.$created['id'], $this->payload(['title' => "$role Event", 'version' => $created['version']]));
            $updated->assertOk()->assertJsonPath('data.version', 2);
            $published = $this->postJson('/api/events/'.$created['id'].'/publish', ['version' => 2]);
            $published->assertOk()->assertJsonPath('data.managed_status', 'published');
            $cancelled = $this->postJson('/api/events/'.$created['id'].'/cancel', ['version' => 3]);
            $cancelled->assertOk()->assertJsonPath('data.managed_status', 'cancelled');
            $archived = $this->postJson('/api/events/'.$created['id'].'/archive', ['version' => 4]);
            $archived->assertOk()->assertJsonPath('data.managed_status', 'archived');
        }
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_audit_events', 5);
    }

    public function test_guest_student_and_professor_cannot_manage_events_and_inactive_staff_is_denied(): void
    {
        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/events', $this->payload())->assertForbidden();
        }
        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF, 'inactive'));
        $this->postJson('/api/events', $this->payload())->assertForbidden();
        $this->getJson('/api/events')->assertForbidden();
    }

    public function test_student_sees_published_events_for_current_finalized_enrollment_only(): void
    {
        $registrar = $this->user(Role::ADMIN);
        [$studentUser, $academic] = $this->academicStudent();
        Sanctum::actingAs($registrar);
        $visible = $this->postJson('/api/events', $this->payload(['intent' => 'publish', 'audiences' => [['audience_type' => 'section', 'section_id' => $academic['section_id']]]]))->assertCreated()->json('data.id');
        $this->postJson('/api/events', $this->payload(['title' => 'Draft private event']))->assertCreated();
        $this->postJson('/api/events', $this->payload(['title' => 'All students', 'venue' => 'Gymnasium', 'intent' => 'publish']))->assertCreated();
        $this->postJson('/api/events', $this->payload(['title' => 'Correct course', 'venue' => 'IT Laboratory', 'intent' => 'publish', 'audiences' => [['audience_type' => 'course', 'course_id' => $academic['course_id']]]]))->assertCreated();
        $this->postJson('/api/events', $this->payload(['title' => 'Correct year', 'venue' => 'Quadrangle', 'intent' => 'publish', 'audiences' => [['audience_type' => 'year_level', 'year_level' => 1]]]))->assertCreated();
        $otherSection = DB::table('sections')->insertGetId(['course_id' => $academic['course_id'], 'academic_year_id' => $academic['academic_year_id'], 'semester_id' => $academic['semester_id'], 'section_name' => 'BSIT-1B', 'year_level' => 1, 'capacity' => 40, 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/events', $this->payload(['title' => 'Other section', 'venue' => 'Library Hall', 'intent' => 'publish', 'audiences' => [['audience_type' => 'section', 'section_id' => $otherSection]]]))->assertCreated();
        $otherCourse = DB::table('courses')->insertGetId(['department_id' => $academic['department_id'], 'course_code' => 'BSCS', 'course_name' => 'Computer Science', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/events', $this->payload(['title' => 'Other course', 'venue' => 'Science Hall', 'intent' => 'publish', 'audiences' => [['audience_type' => 'course', 'course_id' => $otherCourse]]]))->assertCreated();
        $this->postJson('/api/events', $this->payload(['title' => 'Other year', 'venue' => 'Audio Visual Room', 'intent' => 'publish', 'audiences' => [['audience_type' => 'year_level', 'year_level' => 2]]]))->assertCreated();

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/events')->assertOk()->assertJsonCount(4, 'data.data')
            ->assertJsonFragment(['id' => $visible])->assertJsonFragment(['title' => 'All students'])
            ->assertJsonFragment(['title' => 'Correct course'])->assertJsonFragment(['title' => 'Correct year'])
            ->assertJsonMissing(['title' => 'Other section'])->assertJsonMissing(['title' => 'Other course'])->assertJsonMissing(['title' => 'Other year']);
        $this->getJson('/api/events/'.$visible)->assertOk();
    }

    public function test_event_routes_retain_role_platform_policy(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events')->assertForbidden();

        Sanctum::actingAs($this->user(Role::STUDENT));
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/events')->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/events')->assertForbidden();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-promotions')->assertOk();
    }

    public function test_professor_visibility_is_read_only_and_audience_specific(): void
    {
        $registrar = $this->user(Role::ADMIN);
        Sanctum::actingAs($registrar);
        $professorEvent = $this->postJson('/api/events', $this->payload(['intent' => 'publish', 'audiences' => [['audience_type' => 'all_professors']]]))->assertCreated()->json('data.id');
        $this->postJson('/api/events', $this->payload(['title' => 'Students only', 'venue' => 'Library Hall', 'intent' => 'publish']))->assertCreated();

        Sanctum::actingAs($this->user(Role::PROFESSOR));
        $this->getJson('/api/events')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $professorEvent);
        $this->putJson('/api/events/'.$professorEvent, $this->payload(['version' => 1]))->assertForbidden();
    }

    public function test_overlapping_published_venue_is_rejected_but_draft_may_be_saved(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->postJson('/api/events', $this->payload(['intent' => 'publish']))->assertCreated();
        $this->postJson('/api/events', $this->payload(['title' => 'Conflict', 'venue' => '  main   hall ', 'starts_at' => now()->addDays(5)->addHour()->toDateTimeString(), 'ends_at' => now()->addDays(5)->addHours(3)->toDateTimeString(), 'intent' => 'publish']))
            ->assertUnprocessable()->assertJsonValidationErrors('venue');
        $this->postJson('/api/events', $this->payload(['title' => 'Draft conflict']))->assertCreated();
        $this->assertDatabaseCount('event_audit_events', 3);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'event.created']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'event.published']);
    }

    public function test_stale_update_is_rejected_and_does_not_add_audit_record(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $id = $this->postJson('/api/events', $this->payload())->assertCreated()->json('data.id');
        $this->putJson('/api/events/'.$id, $this->payload(['version' => 1]))->assertOk();
        $this->putJson('/api/events/'.$id, $this->payload(['title' => 'Stale', 'version' => 1]))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('event_audit_events', 2);
    }

    public function test_invalid_dates_inactive_targets_and_unknown_audience_are_rejected(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->postJson('/api/events', $this->payload(['ends_at' => now()->addDays(4)->toDateTimeString()]))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/events', $this->payload(['audiences' => [['audience_type' => 'secret_group']]]))->assertUnprocessable()->assertJsonValidationErrors('audiences.0.audience_type');
        $this->postJson('/api/events', $this->payload(['audiences' => [['audience_type' => 'course', 'course_id' => 99999]]]))->assertUnprocessable()->assertJsonValidationErrors('audiences.0.course_id');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Foundation Event', 'description' => 'Event description', 'venue' => 'Main Hall',
            'starts_at' => now()->addDays(5)->toDateTimeString(), 'ends_at' => now()->addDays(5)->addHours(2)->toDateTimeString(),
            'intent' => 'draft', 'audiences' => [['audience_type' => 'all_students']],
        ], $overrides);
    }

    private function user(string $roleName, string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::query()->create(['username' => strtolower(str_replace(' ', '_', $roleName)).'_'.(++$this->sequence), 'password' => 'password', 'role_id' => $role->id, 'status' => $status, 'is_first_login' => false]);
    }

    private function academicStudent(): array
    {
        $user = $this->user(Role::STUDENT);
        $department = DB::table('departments')->insertGetId(['department_code' => 'CSD', 'department_name' => 'Computing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => 'BSIT', 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'BSIT-2026', 'curriculum_name' => 'BSIT 2026', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $profile = DB::table('user_profiles')->insertGetId(['user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Student', 'gender' => 'Female', 'nationality' => 'Filipino', 'created_at' => now(), 'updated_at' => now()]);
        $student = DB::table('students')->insertGetId(['user_id' => $user->id, 'user_profile_id' => $profile, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => '2026-00001', 'admission_date' => now()->toDateString(), 'year_level' => 1, 'student_status' => 'regular', 'created_at' => now(), 'updated_at' => now()]);
        $year = DB::table('academic_years')->insertGetId(['school_year' => '2026-2027', 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $semester = DB::table('semesters')->insertGetId(['semester_name' => 'First Semester', 'semester_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $section = DB::table('sections')->insertGetId(['course_id' => $course, 'academic_year_id' => $year, 'semester_id' => $semester, 'section_name' => 'BSIT-1A', 'year_level' => 1, 'capacity' => 40, 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $student, 'section_id' => $section, 'academic_year_id' => $year, 'semester_id' => $semester, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, ['department_id' => $department, 'course_id' => $course, 'academic_year_id' => $year, 'semester_id' => $semester, 'section_id' => $section]];
    }
}

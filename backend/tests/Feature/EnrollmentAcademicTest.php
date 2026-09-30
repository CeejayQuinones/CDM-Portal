<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentWorkflowEvent;
use App\Models\EnrollmentSubject;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class EnrollmentAcademicTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->f = EnrollmentAcademicFixture::create('academic');
        Sanctum::actingAs($this->f['registrar']);
    }

    private function base(): string
    {
        return '/api/enrollment/academic';
    }

    private function action(string $action, array $extra = [])
    {
        $a = $this->f['application']->fresh();

        return $this->postJson($this->base().'/applications/'.$a->id.'/'.$action, ['version' => $a->version, 'confirmed' => true] + $extra);
    }

    private function schedule(array $extra = [])
    {
        return $this->postJson($this->base().'/schedules', array_replace(['section_id' => $this->f['section']->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id, 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'AC Room'], $extra));
    }

    private function prepare(): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $this->action('subjects', ['subject_ids' => []])->assertOk();
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
    }

    public function test_scheduling_subjects_match_section_year_and_missing_curriculum_is_empty(): void
    {
        $url = $this->base().'/schedules?section_id='.$this->f['section']->id;
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.subjects');
        $this->schedule(['subject_id' => $this->f['subjects'][2]->id])->assertUnprocessable();
        $this->f['section']->update(['year_level' => 4]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->f['section']->update(['year_level' => 1]);
        $this->f['curriculum']->update(['status' => 'inactive']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->schedule()->assertUnprocessable();
    }

    public function test_regular_proposal_and_atomic_finalization_reuse_identity_and_are_idempotent(): void
    {
        $counts = [];
        foreach (['users', 'user_profiles', 'students', 'courses', 'curriculums', 'subjects', 'professors'] as $t) {
            $counts[$t] = DB::table($t)->count();
        }
        $this->getJson($this->base().'/applications/'.$this->f['application']->id)->assertOk()->assertJsonCount(2, 'data.load.standard_subject_ids');
        $this->prepare();
        $before = $this->f['application']->fresh()->version;
        $a = $this->action('finalize')->assertOk()->assertJsonPath('data.status', 'enrolled')->json('data');
        $this->postJson($this->base().'/applications/'.$a['id'].'/finalize', ['version' => $before, 'confirmed' => true])->assertOk()->assertJsonPath('data.enrollment_id', $a['enrollment_id']);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('enrollment_subjects', 2);
        $this->assertSame(1, EnrollmentWorkflowEvent::where('action', 'enrollment.finalized')->count());
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        Sanctum::actingAs($this->f['user']);
        $this->getJson($this->base().'/records/'.$a['enrollment_id'])->assertOk()->assertJsonPath('data.total_units', 6)->assertJsonCount(2, 'data.subjects')->assertJsonPath('data.student_number', $this->f['student']->student_number);
        $this->getJson($this->base().'/notifications')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_student_records_use_term_ranked_enrollment_and_reflect_section_changes(): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $this->action('subjects', ['subject_ids' => []])->assertOk();
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', $this->f['section']->section_name)
            ->assertJsonPath('data.0.current_enrollment.assignment_state', 'assigned_pending_finalization');

        $replacement = Section::create(['course_id' => $this->f['course']->id,
            'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id,
            'section_name' => 'CURRENT-B', 'year_level' => 1, 'capacity' => 40, 'status' => 'open']);
        $this->action('assign', ['section_id' => $replacement->id])->assertOk();
        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', 'CURRENT-B')
            ->assertJsonPath('data.0.current_enrollment.assignment_state', 'assigned_pending_finalization');
        $this->schedule(['section_id' => $replacement->id, 'start_time' => '12:00', 'end_time' => '13:00'])->assertCreated();
        $this->schedule(['section_id' => $replacement->id, 'subject_id' => $this->f['subjects'][1]->id,
            'start_time' => '13:00', 'end_time' => '14:00'])->assertCreated();
        $currentId = $this->action('finalize')->assertOk()->json('data.enrollment_id');
        $previousYear = AcademicYear::create(['school_year' => '2024-2025', 'start_date' => '2024-06-01',
            'end_date' => '2025-05-31', 'status' => 'completed']);
        $historicalSection = Section::create(['course_id' => $this->f['course']->id,
            'academic_year_id' => $previousYear->id, 'semester_id' => $this->f['semester']->id,
            'section_name' => 'HISTORICAL-A', 'year_level' => 1, 'capacity' => 40, 'status' => 'closed']);
        Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $historicalSection->id,
            'academic_year_id' => $previousYear->id, 'semester_id' => $this->f['semester']->id,
            'enrollment_date' => '2024-06-01', 'status' => 'completed']);

        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', 'CURRENT-B')
            ->assertJsonPath('data.0.current_enrollment.id', $currentId)
            ->assertJsonPath('data.0.current_enrollment.assignment_state', 'official')
            ->assertJsonPath('data.0.current_enrollment.record_scope', 'current');
        $this->getJson('/api/students/'.$this->f['student']->id.'/history')->assertOk()
            ->assertJsonPath('data.overview.section', 'CURRENT-B')
            ->assertJsonPath('data.academic_history.1.section', 'HISTORICAL-A');

        $this->f['period']->update(['enabled' => false]);
        $this->f['year']->update(['status' => 'completed']);
        $this->f['semester']->update(['status' => 'inactive']);
        $this->getJson('/api/students?search='.$this->f['student']->student_number)->assertOk()
            ->assertJsonPath('data.0.current_section', 'CURRENT-B')
            ->assertJsonPath('data.0.current_enrollment.record_scope', 'latest');
    }

    public function test_continuing_student_without_admission_or_section_has_empty_section_state(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Continuing', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $student = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id,
            'course_id' => $this->f['course']->id, 'curriculum_id' => $this->f['curriculum']->id,
            'student_number' => 'CONTINUING-NO-SECTION', 'year_level' => 2,
            'admission_date' => now()->subYear(), 'student_status' => 'regular']);

        $this->getJson('/api/students?search=CONTINUING-NO-SECTION')->assertOk()
            ->assertJsonPath('data.0.current_section', null)
            ->assertJsonPath('data.0.admission_record', 'No linked Admission record');
        $this->getJson('/api/students/'.$student->id.'/history')->assertOk()
            ->assertJsonPath('data.overview.section', null)
            ->assertJsonPath('data.overview.section_state', 'not_assigned')
            ->assertJsonPath('data.entry_classification', 'Continuing');
    }

    public function test_irregular_selection_duplicates_curriculum_and_staff_review(): void
    {
        $a = $this->f['application'];
        $a->update(['classification' => 'irregular']);
        Sanctum::actingAs($this->f['user']);
        $id = $this->f['subjects'][0]->id;
        $this->action('subjects', ['subject_ids' => [$id, $id]])->assertUnprocessable();
        $this->action('subjects', ['subject_ids' => [999999]])->assertUnprocessable();
        $this->action('subjects', ['subject_ids' => [$id]])->assertOk()->assertJsonPath('data.load_reviewed_at', null);
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertForbidden();
        $this->action('finalize')->assertForbidden();
        Sanctum::actingAs($this->f['registrar']);
        $this->action('subjects', ['subject_ids' => [$id]])->assertOk()->assertJsonPath('data.load_reviewed_by', $this->f['registrar']->id);
        $this->getJson($this->base().'/applications/'.$a->id)->assertJsonPath('data.total_units', 3);
    }

    public function test_completed_subjects_and_missing_prerequisites_are_blocked(): void
    {
        $this->f['application']->update(['classification' => 'irregular']);
        $subject = $this->f['subjects'][1];
        $subject->update(['prerequisite_subject_id' => $this->f['subjects'][0]->id]);
        $this->action('subjects', ['subject_ids' => [$subject->id]])->assertUnprocessable();
        $previous = AcademicYear::create(['school_year' => 'PRIOR-ACADEMIC', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'completed']);
        $e = Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $previous->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => '2024-06-01', 'status' => 'completed']);
        EnrollmentSubject::create(['enrollment_id' => $e->id, 'subject_id' => $this->f['subjects'][0]->id, 'subject_status' => 'completed', 'remarks' => 'Passed']);
        $this->action('subjects', ['subject_ids' => [$subject->id]])->assertOk();
        $this->action('subjects', ['subject_ids' => [$this->f['subjects'][0]->id]])->assertUnprocessable();
    }

    public function test_transferee_returnee_loads_require_staff_and_preserve_classification(): void
    {
        foreach (['transferee', 'returnee'] as $classification) {
            $this->f['application']->update(['classification' => $classification]);
            Sanctum::actingAs($this->f['user']);
            $this->action('subjects', ['subject_ids' => [$this->f['subjects'][0]->id]])->assertForbidden();
            Sanctum::actingAs($this->f['registrar']);
            $this->action('subjects', ['subject_ids' => [$this->f['subjects'][0]->id]])->assertOk()->assertJsonPath('data.classification', $classification);
        }
    }

    public function test_unapproved_stale_and_ineligible_finalizations_fail(): void
    {
        $this->f['application']->update(['status' => 'submitted']);
        $this->action('finalize')->assertConflict();
        $this->f['application']->update(['status' => 'approved']);
        $this->prepare();
        $this->postJson($this->base().'/applications/'.$this->f['application']->id.'/finalize', ['version' => 1, 'confirmed' => true])->assertConflict();
        $this->f['student']->update(['student_status' => 'leave_of_absence']);
        $this->action('finalize')->assertUnprocessable();
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_capacity_reservations_and_stale_assignments_are_enforced(): void
    {
        $this->f['section']->update(['capacity' => 1]);
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Second', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $s = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $this->f['course']->id, 'curriculum_id' => $this->f['curriculum']->id, 'student_number' => 'ACADEMIC-SECOND', 'year_level' => 1, 'admission_date' => now()->toDateString(), 'student_status' => 'regular']);
        $a = $this->f['application']->replicate();
        $a->student_id = $s->id;
        $a->section_id = null;
        $a->save();
        $this->postJson($this->base().'/applications/'.$a->id.'/assign', ['version' => $a->version, 'section_id' => $this->f['section']->id])->assertConflict();
        $this->getJson($this->base().'/sections')->assertJsonPath('data.data.0.reserved_count', 1);
    }

    public function test_finalization_audit_failure_rolls_back_all_records_and_notifications(): void
    {
        $this->prepare();
        $notices = DB::table('notifications')->count();
        EnrollmentWorkflowEvent::creating(function ($event) {
            if ($event->action === 'enrollment.finalized') {
                throw new \RuntimeException('private test failure');
            }
        });
        $this->action('finalize')->assertStatus(503)->assertDontSee('private test failure');
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('enrollment_subjects', 0);
        $this->assertDatabaseCount('notifications', $notices);
        $this->assertNull($this->f['application']->fresh()->enrollment_id);
    }

    public function test_existing_term_enrollment_is_not_overwritten(): void
    {
        $this->prepare();
        Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => now()->toDateString(), 'status' => 'cancelled']);
        $this->action('finalize')->assertConflict();
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('enrollment_subjects', 0);
    }

    public function test_schedule_conflicts_for_section_professor_room_and_adjacent_classes(): void
    {
        $this->schedule()->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '09:30', 'end_time' => '10:30', 'room' => 'Different'])->assertConflict();
        $other = $this->f['section']->replicate();
        $other->section_name = 'OTHER';
        $other->save();
        $this->schedule(['section_id' => $other->id, 'room' => 'Different'])->assertConflict();
        $pu = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $pp = $pu->profile()->create(['first_name' => 'Other', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        $p = Professor::create(['user_id' => $pu->id, 'user_profile_id' => $pp->id, 'department_id' => $this->f['department']->id, 'employee_number' => 'OTHER-P', 'status' => 'active']);
        $this->schedule(['section_id' => $other->id, 'professor_id' => $p->id, 'room' => ' ac room '])->assertConflict();
        $this->schedule(['subject_id' => $this->f['subjects'][1]->id, 'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $this->schedule(['subject_id' => $this->f['subjects'][2]->id, 'start_time' => '11:00', 'end_time' => '10:00'])->assertUnprocessable();
        $this->schedule(['day' => 'Invalid'])->assertUnprocessable();
    }

    public function test_schedule_versions_edits_deletion_and_referenced_protection(): void
    {
        $this->prepare();
        $s = SectionSubject::first();
        $payload = $s->only(['section_id', 'subject_id', 'professor_id', 'day', 'room', 'version']);
        $payload += ['start_time' => '08:00', 'end_time' => '09:00'];
        $this->putJson($this->base().'/schedules/'.$s->id, $payload)->assertOk();
        $this->putJson($this->base().'/schedules/'.$s->id, $payload)->assertConflict();
        $this->action('finalize')->assertOk();
        $this->deleteJson($this->base().'/schedules/'.$s->id, ['version' => $s->fresh()->version, 'confirmed' => true])->assertConflict();
    }

    public function test_both_staff_can_manage_sections_and_invalid_year_or_capacity_fails(): void
    {
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $i => $role) {
            $u = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
            Sanctum::actingAs($u);
            $payload = ['course_id' => $this->f['course']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'section_name' => 'NEW-'.$i, 'year_level' => 1, 'capacity' => 20, 'status' => 'open'];
            $s = $this->postJson($this->base().'/sections', $payload)->assertCreated()->json('data');
            $this->putJson($this->base().'/sections/'.$s['id'], $payload + ['version' => 1])->assertOk();
            $this->postJson($this->base().'/sections', array_replace($payload, ['year_level' => 20]))->assertUnprocessable();
        }
        $this->action('assign', ['section_id' => $this->f['section']->id])->assertOk();
        $s = $this->f['section']->fresh();
        $this->putJson($this->base().'/sections/'.$s->id, array_replace($s->toArray(), ['capacity' => 0]))->assertUnprocessable();
    }

    public function test_ownership_professor_rosters_staff_equality_and_cor_boundaries(): void
    {
        $this->prepare();
        $a = $this->action('finalize')->assertOk()->json('data');
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            $u = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]);
            Sanctum::actingAs($u);
            $this->getJson($this->base().'/records?search='.urlencode($this->f['student']->student_number).'&sort=name&classification=regular')->assertOk()->assertJsonPath('data.total', 1);
            $this->getJson($this->base().'/records/'.$a['enrollment_id'])->assertOk();
        }
        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            $u = User::factory()->create(['role_id' => Role::where('role_name', $role)->value('id')]);
            Sanctum::actingAs($u);
            $this->getJson($this->base().'/sections')->assertForbidden();
            $this->getJson($this->base().'/records/'.$a['enrollment_id'])->assertStatus($role === Role::STUDENT ? 404 : 403);
        }
        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson($this->base().'/professor')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($this->base().'/professor/sections/'.$this->f['section']->id)->assertOk()->assertJsonPath('data.total', 1);
        $other = $this->f['section']->replicate();
        $other->section_name = 'UNASSIGNED';
        $other->save();
        $this->getJson($this->base().'/professor/sections/'.$other->id)->assertNotFound();
        $staff = $this->f['registrar'];
        $staff->update(['status' => 'inactive']);
        Sanctum::actingAs($staff);
        $this->getJson($this->base().'/sections')->assertForbidden();
    }
}

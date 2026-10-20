<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\GradeSheet;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSubjectPolicy;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class SubjectManagementTest extends TestCase
{
    private array $fixture;

    private Curriculum $bsba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('subjects');
        $course = Course::create([
            'department_id' => $this->fixture['department']->id,
            'course_code' => 'BSBA', 'course_name' => 'Business Administration',
            'years' => 4, 'status' => 'active',
        ]);
        $this->bsba = Curriculum::create([
            'course_id' => $course->id, 'curriculum_code' => 'BSBA-2026',
            'curriculum_name' => 'BSBA 2026 Curriculum', 'effective_year' => 2026,
            'status' => 'active',
        ]);
        Sanctum::actingAs($this->fixture['registrar']);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/enrollment/academic/subjects'.$suffix;
    }

    private function payload(string $code = 'BUS101', array $replace = []): array
    {
        return array_replace([
            'curriculum_id' => $this->bsba->id,
            'semester_id' => $this->fixture['semester']->id,
            'subject_code' => $code,
            'subject_name' => 'Introduction to Business',
            'year_level' => 1,
            'units' => 3,
            'lecture_hours' => 3,
            'laboratory_hours' => 0,
            'prerequisite_subject_id' => null,
            'status' => 'active',
        ], $replace);
    }

    public function test_registrar_and_admin_can_create_while_other_roles_and_disabled_staff_are_denied(): void
    {
        $this->postJson($this->url(), $this->payload('BUS101'))
            ->assertCreated()
            ->assertJsonPath('data.subject_code', 'BUS101')
            ->assertJsonPath('data.curriculum.course.course_code', 'BSBA');

        $admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);
        $this->postJson($this->url(), $this->payload('ACC101'))->assertCreated();

        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            $user = User::factory()->create([
                'role_id' => Role::firstOrCreate(['role_name' => $role])->id,
                'status' => 'active',
            ]);
            Sanctum::actingAs($user);
            $this->getJson($this->url())->assertForbidden();
            $this->postJson($this->url(), $this->payload('DENIED-'.$role))->assertForbidden();
        }

        $this->fixture['registrar']->update(['status' => 'suspended']);
        Sanctum::actingAs($this->fixture['registrar']);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_codes_are_normalized_globally_and_invalid_units_are_rejected(): void
    {
        $created = $this->postJson($this->url(), $this->payload(' bus 101 '))
            ->assertCreated()
            ->assertJsonPath('data.subject_code', 'BUS101')
            ->json('data');

        $this->postJson($this->url(), $this->payload('b u s101', [
            'curriculum_id' => $this->fixture['curriculum']->id,
        ]))->assertUnprocessable();
        $this->postJson($this->url(), $this->payload('INVALID', ['units' => -1]))->assertUnprocessable();
        $this->postJson($this->url(), $this->payload('INVALID CODE!'))->assertUnprocessable();

        $edit = $this->payload('BUS101', [
            'subject_name' => 'Business Foundations',
            'lecture_hours' => 2,
        ]);
        unset($edit['status']);
        $this->putJson($this->url('/'.$created['id']), $edit)
            ->assertOk()
            ->assertJsonPath('data.subject_name', 'Business Foundations')
            ->assertJsonPath('data.lecture_hours', 2);
    }

    public function test_unused_subject_can_be_assigned_to_another_curriculum_and_assignment_is_audited(): void
    {
        $subject = Subject::create($this->payload('MKT101', [
            'curriculum_id' => $this->fixture['curriculum']->id,
        ]));
        $payload = $this->payload('MKT101', ['subject_name' => 'Principles of Marketing']);
        unset($payload['status']);

        $this->putJson($this->url('/'.$subject->id), $payload)
            ->assertOk()
            ->assertJsonPath('data.curriculum_id', $this->bsba->id);
        $this->assertDatabaseHas('enrollment_workflow_events', [
            'action' => 'enrollment.subject_curriculum_assigned',
            'subject_type' => 'subject',
            'subject_id' => $subject->id,
        ]);
    }

    public function test_archive_preserves_references_and_blocks_new_schedule_assignment(): void
    {
        $subject = Subject::create($this->payload('ACC101'));
        $section = Section::create([
            'course_id' => $this->bsba->course_id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'section_name' => 'BSBA-1A', 'year_level' => 1,
            'capacity' => 40, 'status' => 'open',
        ]);
        $assignment = SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'professor_id' => $this->fixture['professor']->id,
            'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'room' => '201',
        ]);
        $grades = GradeSheet::count();

        $unsafe = $this->payload('ACC102');
        unset($unsafe['status']);
        $this->putJson($this->url('/'.$subject->id), $unsafe)->assertConflict();
        $this->postJson($this->url('/'.$subject->id.'/archive'))->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('section_subjects', ['id' => $assignment->id, 'subject_id' => $subject->id]);
        $this->assertSame($grades, GradeSheet::count());
        $this->getJson('/api/enrollment/academic/schedules?section_id='.$section->id)
            ->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->postJson('/api/enrollment/academic/schedules', [
            'section_id' => $section->id, 'subject_id' => $subject->id,
            'professor_id' => $this->fixture['professor']->id,
            'day' => 'Tuesday', 'start_time' => '08:00', 'end_time' => '09:00', 'room' => '202',
        ])->assertUnprocessable();
        $this->deleteJson($this->url('/'.$subject->id))->assertStatus(405);
        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'status' => 'inactive']);
        $this->postJson($this->url('/'.$subject->id.'/reactivate'))->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_bsba_catalog_isolated_from_other_programs_and_drives_enrollment_and_scheduling(): void
    {
        $business = Subject::create($this->payload('BUS101'));
        $other = $this->fixture['subjects'][0];
        $application = new EnrollmentApplication([
            'curriculum_id' => $this->bsba->id,
            'semester_id' => $this->fixture['semester']->id,
            'year_level' => 1,
            'classification' => 'regular',
        ]);
        $candidates = app(EnrollmentSubjectPolicy::class)->candidates($application);
        $this->assertSame([$business->id], $candidates->pluck('id')->all());
        $this->assertNotContains($other->id, $candidates->pluck('id')->all());

        $section = Section::create([
            'course_id' => $this->bsba->course_id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'section_name' => 'BSBA-CATALOG', 'year_level' => 1,
            'capacity' => 40, 'status' => 'open',
        ]);
        $this->getJson('/api/enrollment/academic/schedules?section_id='.$section->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.subject_code', 'BUS101');
        $this->getJson($this->url().'?course_id='.$this->bsba->course_id)
            ->assertOk()
            ->assertJsonPath('data.subjects.total', 1)
            ->assertJsonPath('data.subjects.data.0.curriculum.course.course_code', 'BSBA');

        $empty = Curriculum::create([
            'course_id' => $this->bsba->course_id, 'curriculum_code' => 'BSBA-EMPTY',
            'curriculum_name' => 'Empty BSBA Curriculum', 'effective_year' => 2027, 'status' => 'active',
        ]);
        $this->getJson($this->url().'?curriculum_id='.$empty->id)
            ->assertOk()->assertJsonPath('data.subjects.total', 0);
    }
}

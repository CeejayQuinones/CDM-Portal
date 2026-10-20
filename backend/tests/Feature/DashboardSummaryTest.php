<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Event;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    private array $fixture;

    private array $web = ['X-CDM-Client' => 'web'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('dashboards');
    }

    public function test_student_dashboard_uses_only_authenticated_student_live_data(): void
    {
        $enrollment = Enrollment::create([
            'student_id' => $this->fixture['student']->id,
            'section_id' => $this->fixture['section']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'enrolled',
        ]);
        foreach (array_slice($this->fixture['subjects'], 0, 2) as $subject) {
            EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'subject_id' => $subject->id,
                'professor_id' => $this->fixture['professor']->id,
                'subject_status' => 'enrolled',
            ]);
        }
        RiskNotification::create([
            'student_id' => $this->fixture['student']->id,
            'sender_user_id' => $this->fixture['registrar']->id,
            'risk_level' => 'moderate',
            'title' => 'Review your plan',
            'message' => 'A current notice.',
        ]);
        RiskNotification::create([
            'student_id' => $this->fixture['student']->id,
            'sender_user_id' => $this->fixture['registrar']->id,
            'risk_level' => 'low',
            'title' => 'Already reviewed',
            'message' => 'An older notice.',
            'read_at' => now(),
        ]);
        Event::create([
            'title' => 'Student orientation',
            'venue' => 'Campus hall',
            'venue_key' => 'campus-hall',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'status' => 'published',
            'created_by' => $this->fixture['registrar']->id,
            'updated_by' => $this->fixture['registrar']->id,
        ]);
        Event::create([
            'title' => 'Unpublished event',
            'venue' => 'Campus hall',
            'venue_key' => 'campus-hall',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'status' => 'draft',
            'created_by' => $this->fixture['registrar']->id,
            'updated_by' => $this->fixture['registrar']->id,
        ]);

        Sanctum::actingAs($this->fixture['user']);
        $this->getJson('/api/student/dashboard', $this->web)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.identity.student_number', $this->fixture['student']->student_number)
            ->assertJsonPath('data.identity.program_code', $this->fixture['course']->course_code)
            ->assertJsonPath('data.identity.year_level', 1)
            ->assertJsonPath('data.identity.section', 'AC-dashboards')
            ->assertJsonPath('data.summary.enrollment_status', 'enrolled')
            ->assertJsonPath('data.summary.enrolled_subjects', 2)
            ->assertJsonPath('data.summary.unread_monitoring_notices', 1)
            ->assertJsonPath('data.summary.upcoming_events', 1)
            ->assertJsonPath('data.has_finalized_enrollment', true);
    }

    public function test_student_dashboard_has_a_safe_empty_state_without_an_academic_record(): void
    {
        $user = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::STUDENT])->id]);
        $user->profile()->create(['first_name' => 'New', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);

        Sanctum::actingAs($user);
        $this->getJson('/api/student/dashboard', $this->web)
            ->assertOk()
            ->assertJsonPath('data.identity.name', 'New Student')
            ->assertJsonPath('data.identity.student_number', null)
            ->assertJsonPath('data.summary.enrolled_subjects', 0)
            ->assertJsonPath('data.summary.unread_monitoring_notices', 0)
            ->assertJsonPath('data.has_finalized_enrollment', false);
    }

    public function test_professor_dashboard_counts_only_owned_active_assignments_and_matching_students(): void
    {
        $enrollment = Enrollment::create([
            'student_id' => $this->fixture['student']->id,
            'section_id' => $this->fixture['section']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'enrolled',
        ]);
        $first = SectionSubject::create(['section_id' => $this->fixture['section']->id, 'subject_id' => $this->fixture['subjects'][0]->id, 'professor_id' => $this->fixture['professor']->id]);
        $second = SectionSubject::create(['section_id' => $this->fixture['section']->id, 'subject_id' => $this->fixture['subjects'][1]->id, 'professor_id' => $this->fixture['professor']->id]);
        foreach ([$first, $second] as $assignment) {
            EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'subject_id' => $assignment->subject_id,
                'professor_id' => $this->fixture['professor']->id,
                'subject_status' => 'enrolled',
            ]);
        }
        GradeSheet::create(['section_subject_id' => $first->id, 'professor_id' => $this->fixture['professor']->id, 'status' => 'returned']);
        GradeSheet::create(['section_subject_id' => $second->id, 'professor_id' => $this->fixture['professor']->id, 'status' => 'submitted']);

        $foreignUser = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $foreignProfile = $foreignUser->profile()->create(['first_name' => 'Other', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        $foreignProfessor = Professor::create([
            'user_id' => $foreignUser->id,
            'user_profile_id' => $foreignProfile->id,
            'department_id' => $this->fixture['department']->id,
            'employee_number' => 'FOREIGN-DASHBOARD',
            'status' => 'active',
        ]);
        $foreignAssignment = SectionSubject::create(['section_id' => $this->fixture['section']->id, 'subject_id' => $this->fixture['subjects'][2]->id, 'professor_id' => $foreignProfessor->id]);
        EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $foreignAssignment->subject_id,
            'professor_id' => $foreignProfessor->id,
            'subject_status' => 'enrolled',
        ]);
        GradeSheet::create(['section_subject_id' => $foreignAssignment->id, 'professor_id' => $foreignProfessor->id, 'status' => 'draft']);

        Sanctum::actingAs($this->fixture['professorUser']);
        $this->getJson('/api/professor/dashboard', $this->web)
            ->assertOk()
            ->assertJsonPath('data.identity.faculty_id', 'AP-dashboards')
            ->assertJsonPath('data.identity.department', $this->fixture['department']->department_name)
            ->assertJsonPath('data.summary.assigned_classes', 2)
            ->assertJsonPath('data.summary.students', 1)
            ->assertJsonPath('data.summary.grade_sheets_needing_work', 1)
            ->assertJsonPath('data.summary.submitted_grade_sheets', 1)
            ->assertJsonStructure(['data' => ['summary' => ['monitoring_alerts', 'high_risk_alerts', 'attention_alerts']]])
            ->assertJsonPath('data.has_assignments', true);
    }

    public function test_dashboard_role_and_professor_platform_boundaries_are_enforced(): void
    {
        Sanctum::actingAs($this->fixture['user']);
        $this->getJson('/api/professor/dashboard', $this->web)->assertForbidden();

        Sanctum::actingAs($this->fixture['professorUser']);
        $this->getJson('/api/student/dashboard', $this->web)->assertForbidden();
        $this->getJson('/api/professor/dashboard', ['X-CDM-Client' => 'mobile'])
            ->assertOk();
    }

    public function test_professor_without_assignments_receives_zero_counts_and_empty_state_flag(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Unassigned', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        Professor::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'department_id' => $this->fixture['department']->id,
            'employee_number' => 'NO-ASSIGNMENT',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/professor/dashboard', $this->web)
            ->assertOk()
            ->assertJsonPath('data.summary.assigned_classes', 0)
            ->assertJsonPath('data.summary.students', 0)
            ->assertJsonPath('data.summary.grade_sheets_needing_work', 0)
            ->assertJsonPath('data.summary.submitted_grade_sheets', 0)
            ->assertJsonPath('data.has_assignments', false);
    }
}

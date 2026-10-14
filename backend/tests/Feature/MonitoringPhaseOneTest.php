<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeSheet;
use App\Models\GradeSubmissionAttempt;
use App\Models\GradeSubmissionStudent;
use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class MonitoringPhaseOneTest extends TestCase
{
    private array $fixture;

    private Enrollment $enrollment;

    /** @var array<int, SectionSubject> */
    private array $assignments;

    /** @var array<int, EnrollmentSubject> */
    private array $enrollmentSubjects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('monitoring-one');
        $this->enrollment = Enrollment::create([
            'student_id' => $this->fixture['student']->id,
            'section_id' => $this->fixture['section']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'enrollment_date' => today(),
            'status' => 'enrolled',
        ]);

        foreach ([0, 1] as $index) {
            $this->assignments[$index] = SectionSubject::create([
                'section_id' => $this->fixture['section']->id,
                'subject_id' => $this->fixture['subjects'][$index]->id,
                'professor_id' => $this->fixture['professor']->id,
            ]);
            $this->enrollmentSubjects[$index] = EnrollmentSubject::create([
                'enrollment_id' => $this->enrollment->id,
                'subject_id' => $this->fixture['subjects'][$index]->id,
                'professor_id' => $this->fixture['professor']->id,
                'subject_status' => 'enrolled',
            ]);
        }
    }

    public function test_student_sees_only_own_published_latest_snapshot_with_transparent_risk_and_completeness(): void
    {
        $this->snapshot(0, 'published', 95, 70, 80);
        $this->snapshot(1, 'approved', 40, 30, 35);

        Sanctum::actingAs($this->fixture['user']);
        $response = $this->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.student_id', $this->fixture['student']->id)
            ->assertJsonPath('data.students.0.risk_level', 'high')
            ->assertJsonPath('data.students.0.trend', 'declining')
            ->assertJsonPath('data.students.0.subjects_analyzed', 1)
            ->assertJsonPath('data.students.0.data_completeness.status', 'partial')
            ->assertJsonPath('data.students.0.data_completeness.expected_subjects', 2)
            ->assertJsonPath('data.students.0.subjects.0.midterm_grade', 95)
            ->assertJsonPath('data.students.0.subjects.0.finals_grade', 70)
            ->assertJsonPath('data.students.0.subjects.0.checkpoint_change', -25);

        $this->assertArrayNotHasKey('average_grade', $response->json('data.students.0'));
        $this->assertArrayNotHasKey('gwa', $response->json('data.students.0'));
        $this->assertStringContainsString('Finals decreased by 25 points', $response->json('data.students.0.reasons.0'));
        $this->assertStringContainsString('Draft, Submitted, Returned, and Approved', implode(' ', $response->json('data.methodology.excluded')));
    }

    public function test_latest_submission_is_used_and_missing_publication_is_insufficient(): void
    {
        $sheet = $this->snapshot(0, 'published', 92, 68, 78);
        $this->addAttempt($sheet, $this->enrollmentSubjects[0], 2, 78, 88, 84);

        Sanctum::actingAs($this->fixture['user']);
        $this->getJson('/api/monitoring/my-risk')->assertOk()
            ->assertJsonPath('data.students.0.risk_level', 'stable')
            ->assertJsonPath('data.students.0.trend', 'improving')
            ->assertJsonPath('data.students.0.subjects.0.final_grade', 84);

        $sheet->update(['status' => 'approved', 'published_at' => null]);
        $this->getJson('/api/monitoring/my-risk')->assertOk()
            ->assertJsonPath('data.students.0.risk_level', 'insufficient')
            ->assertJsonPath('data.students.0.trend_label', 'Insufficient data')
            ->assertJsonPath('data.students.0.data_completeness.status', 'no_published_data')
            ->assertJsonCount(0, 'data.students.0.subjects');
    }

    public function test_lower_quarter_signal_is_computed_within_the_same_published_class_snapshot(): void
    {
        $sheet = $this->snapshot(0, 'published', 80, 80, 60);
        $attempt = $sheet->latestSubmission()->firstOrFail();

        foreach ([70, 80, 90] as $index => $final) {
            $student = $this->student('Cohort '.$index, 'MON-C'.$index);
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'section_id' => $this->fixture['section']->id,
                'academic_year_id' => $this->fixture['year']->id,
                'semester_id' => $this->fixture['semester']->id,
                'enrollment_date' => today(),
                'status' => 'enrolled',
            ]);
            $subject = EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'subject_id' => $this->fixture['subjects'][0]->id,
                'professor_id' => $this->fixture['professor']->id,
                'subject_status' => 'enrolled',
            ]);
            $this->addStudentResult($attempt, $subject, 80, 80, $final);
        }

        Sanctum::actingAs($this->fixture['user']);
        $response = $this->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonPath('data.students.0.risk_level', 'moderate')
            ->assertJsonPath('data.students.0.subjects.0.risk_level', 'moderate')
            ->assertJsonPath('data.students.0.subjects.0.trend', 'stable');

        $this->assertStringContainsString('lower quarter', $response->json('data.students.0.reasons.0'));
    }

    public function test_professor_scope_requires_actual_assignment_and_owned_published_sheet(): void
    {
        $this->snapshot(0, 'published', 90, 70, 78);
        $foreign = $this->professor('Foreign', 'MON-F');
        $this->assignments[1]->update(['professor_id' => $foreign->id]);
        $this->enrollmentSubjects[1]->update(['professor_id' => $foreign->id]);
        $this->snapshot(1, 'published', 75, 95, 87, $foreign);

        Sanctum::actingAs($this->fixture['professorUser']);
        $this->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonCount(1, 'data.students.0.subjects')
            ->assertJsonPath('data.students.0.subjects.0.subject_code', $this->fixture['subjects'][0]->subject_code);

        Sanctum::actingAs($foreign->user);
        $this->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonCount(1, 'data.students.0.subjects')
            ->assertJsonPath('data.students.0.subjects.0.subject_code', $this->fixture['subjects'][1]->subject_code);
    }

    public function test_student_self_scope_staff_visibility_and_guest_denial(): void
    {
        $this->snapshot(0, 'published', 85, 86, 85.5);
        $other = $this->student('Other', 'MON-OTHER');
        $otherEnrollment = Enrollment::create([
            'student_id' => $other->id,
            'section_id' => $this->fixture['section']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'enrollment_date' => today(),
            'status' => 'enrolled',
        ]);
        EnrollmentSubject::create([
            'enrollment_id' => $otherEnrollment->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
            'subject_status' => 'enrolled',
        ]);

        Sanctum::actingAs($this->fixture['user']);
        $this->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.student_id', $this->fixture['student']->id);

        Sanctum::actingAs($this->fixture['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonPath('data.summary.total', 2);

        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id, 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/monitoring/overview')->assertOk()
            ->assertJsonPath('data.summary.total', 2);

        $guest = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::GUEST])->id, 'status' => 'active']);
        Sanctum::actingAs($guest);
        $this->getJson('/api/monitoring/overview')->assertForbidden();
    }

    public function test_monitoring_obeys_role_platform_and_account_status_rules(): void
    {
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id, 'status' => 'active']);

        foreach ([
            [$this->fixture['user'], 'web', 200],
            [$this->fixture['user'], 'mobile', 200],
            [$this->fixture['user'], 'desktop', 403],
            [$this->fixture['professorUser'], 'web', 200],
            [$this->fixture['professorUser'], 'desktop', 403],
            [$this->fixture['registrar'], 'desktop', 200],
            [$this->fixture['registrar'], 'web', 403],
            [$admin, 'web', 200],
            [$admin, 'desktop', 200],
            [$admin, 'mobile', 403],
        ] as [$user, $client, $status]) {
            Sanctum::actingAs($user);
            $this->withHeader('X-CDM-Client', $client)->getJson('/api/monitoring/overview')->assertStatus($status);
        }

        foreach (['inactive', 'suspended'] as $status) {
            $admin->update(['status' => $status]);
            Sanctum::actingAs($admin->fresh());
            $this->withHeader('X-CDM-Client', 'web')->getJson('/api/monitoring/overview')->assertForbidden();
        }
    }

    private function snapshot(int $index, string $status, float $midterm, float $finals, float $final, ?Professor $professor = null): GradeSheet
    {
        $professor ??= $this->fixture['professor'];
        $sheet = GradeSheet::create([
            'section_subject_id' => $this->assignments[$index]->id,
            'professor_id' => $professor->id,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $this->addAttempt($sheet, $this->enrollmentSubjects[$index], 1, $midterm, $finals, $final);

        return $sheet;
    }

    private function addAttempt(GradeSheet $sheet, EnrollmentSubject $subject, int $number, float $midterm, float $finals, float $final): void
    {
        $attempt = GradeSubmissionAttempt::create([
            'grade_sheet_id' => $sheet->id,
            'attempt_number' => $number,
            'submitted_by' => $sheet->professor->user_id,
            'submitted_at' => now(),
            'sheet_version' => $sheet->version,
            'midterm_weight' => 40,
            'finals_weight' => 60,
            'configuration' => ['source' => 'monitoring-test'],
            'checksum' => str_repeat((string) $number, 64),
        ]);
        $this->addStudentResult($attempt, $subject, $midterm, $finals, $final);
    }

    private function addStudentResult(GradeSubmissionAttempt $attempt, EnrollmentSubject $subject, float $midterm, float $finals, float $final): void
    {
        GradeSubmissionStudent::create([
            'grade_submission_attempt_id' => $attempt->id,
            'enrollment_subject_id' => $subject->id,
            'student_id' => $subject->enrollment->student_id,
            'student_number' => $subject->enrollment->student->student_number,
            'student_name' => 'Monitoring Student',
            'midterm_grade' => $midterm,
            'finals_grade' => $finals,
            'final_grade' => $final,
            'grade_point' => null,
            'remarks' => null,
            'breakdown' => [],
        ]);
    }

    private function professor(string $name, string $number): Professor
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id'), 'status' => 'active']);
        $profile = $user->profile()->create(['first_name' => $name, 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);

        return Professor::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'department_id' => $this->fixture['department']->id,
            'employee_number' => $number,
            'status' => 'active',
        ])->load('user');
    }

    private function student(string $name, string $number): Student
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id'), 'status' => 'active']);
        $profile = $user->profile()->create(['first_name' => $name, 'last_name' => 'Student', 'gender' => 'Prefer not to say']);

        return Student::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id,
            'student_number' => $number,
            'admission_date' => today(),
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
    }
}

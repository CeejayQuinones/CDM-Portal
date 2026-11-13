<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeAuditEvent;
use App\Models\GradePeriodSchedule;
use App\Models\GradeReleaseSchedule;
use App\Models\GradeSheet;
use App\Models\GradingScale;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admission\ProgramMatcher;
use App\Services\Grading\GradingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private User $guest;

    private User $professorUser;

    private Professor $professor;

    private AcademicYear $year;

    private Semester $firstSemester;

    private Course $course;

    private Curriculum $curriculum;

    private EnrollmentPeriod $firstPeriod;

    private Section $section;

    /** @var array<int, Subject> */
    private array $initialSubjects;

    /** @var array<int, SectionSubject> */
    private array $assignments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF, Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            Role::firstOrCreate(['role_name' => $role]);
        }
        $this->admin = $this->user(Role::ADMIN, 'Lifecycle', 'Administrator');
        $this->registrar = $this->user(Role::REGISTRAR_STAFF, 'Lifecycle', 'Registrar');
        $this->guest = $this->user(Role::GUEST, 'Lifecycle', 'Student');
        $this->guest->profile()->update([
            'birth_date' => '2006-05-12',
            'contact_number' => '09171234567',
            'email' => 'lifecycle.student@example.test',
            'address' => 'Rodriguez, Rizal',
        ]);
        $this->professorUser = $this->user(Role::PROFESSOR, 'Lifecycle', 'Professor');

        $this->year = AcademicYear::create([
            'school_year' => '2026-2027', 'start_date' => '2026-06-01',
            'end_date' => '2027-05-31', 'status' => 'active',
        ]);
        $department = Department::create([
            'department_code' => 'LC-ICS', 'department_name' => 'Lifecycle Computing', 'status' => 'active',
        ]);
        $this->course = Course::create([
            'department_id' => $department->id, 'course_code' => 'LC-BSIT',
            'course_name' => 'Lifecycle Information Technology', 'years' => 4, 'status' => 'active',
        ]);
        $this->curriculum = Curriculum::create([
            'course_id' => $this->course->id, 'curriculum_code' => 'LC-BSIT-2026',
            'curriculum_name' => 'Lifecycle BSIT 2026', 'effective_year' => 2026, 'status' => 'active',
        ]);
        $cycle = AdmissionCycle::create([
            'code' => 'LC-2026', 'name' => 'Lifecycle Admission 2026',
            'academic_year_id' => $this->year->id, 'status' => AdmissionCycle::OPEN,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(),
            'confirmation_closes_at' => now()->addDays(2),
            'created_by_user_id' => $this->admin->id, 'updated_by_user_id' => $this->admin->id,
        ]);
        $this->questionBank();

        $this->firstSemester = Semester::create([
            'semester_name' => 'Lifecycle First Semester', 'semester_order' => 1, 'status' => 'active',
        ]);
        $this->firstPeriod = $this->period($this->firstSemester);
        $this->initialSubjects = [
            Subject::create($this->subjectData($this->firstSemester, 'LC101', 'Lifecycle Foundations')),
            Subject::create($this->subjectData($this->firstSemester, 'LC102', 'Lifecycle Programming')),
        ];
        $this->section = Section::create([
            'course_id' => $this->course->id, 'academic_year_id' => $this->year->id,
            'semester_id' => $this->firstSemester->id, 'section_name' => 'LC-BSIT-1A',
            'year_level' => 1, 'capacity' => 40, 'status' => 'open',
        ]);
        $this->professor = Professor::create([
            'user_id' => $this->professorUser->id,
            'user_profile_id' => $this->professorUser->profile->id,
            'department_id' => $department->id,
            'employee_number' => 'LC-PROF-001', 'status' => 'active',
        ]);
        $this->assignments = array_map(fn (Subject $subject): SectionSubject => SectionSubject::create([
            'section_id' => $this->section->id,
            'subject_id' => $subject->id,
            'professor_id' => $this->professor->id,
        ]), $this->initialSubjects);
    }

    public function test_complete_student_lifecycle_preserves_cross_module_identity_and_rules(): void
    {
        $this->authenticateAs($this->guest, 'web');
        $this->postJson('/api/admission/applications')
            ->assertCreated()
            ->assertJsonPath('data.application.status', AdmissionApplicant::DRAFT);
        $applicant = AdmissionApplicant::sole();
        $this->assertSame($this->guest->id, $applicant->user_id);

        $session = $this->postJson('/api/admission/exam/start', ['rules_acknowledged' => true])
            ->assertOk()->json('data');
        $answers = array_fill_keys(array_column($session['questions'], 'id'), 'A');
        $this->postJson('/api/admission/exam/'.$session['session_id'].'/submit', [
            'revision' => $session['revision'], 'position' => 0, 'answers' => $answers,
        ])->assertOk()->assertJsonPath('data.exam_completed', true);
        $result = AdmissionExamResult::where('session_id', $session['session_id'])->sole();

        $otherGuest = $this->user(Role::GUEST, 'Other', 'Applicant');
        $this->authenticateAs($otherGuest, 'web');
        $this->putJson('/api/admission/exam/'.$session['session_id'].'/answers', [
            'revision' => 0, 'position' => 0, 'answers' => $answers,
        ])->assertNotFound();

        $this->authenticateAs($this->registrar, 'desktop');
        $this->postJson('/api/admission/registrar/results/approve', [
            'results' => [['id' => $result->id, 'version' => $result->version]],
        ])->assertOk();
        $result->refresh();
        $this->postJson('/api/admission/registrar/results/publish', [
            'results' => [['id' => $result->id, 'version' => $result->version]],
        ])->assertOk();
        $result->refresh();
        $this->assertSame('PASSED', $result->outcome());
        $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->postJson('/api/admission/registrar/applicants/'.$applicant->id.'/accept', [
            'version' => $applicant->fresh()->version,
            'result_id' => $result->id,
            'result_version' => $result->version,
            'course_id' => $this->course->id,
            'curriculum_id' => $this->curriculum->id,
            'confirmed' => true,
        ])->assertOk();
        $this->assertDatabaseCount('students', 0);

        $this->authenticateAs($this->guest->fresh(), 'web');
        $enrollmentApplication = $this->postJson('/api/enrollment/applications', [
            'period_id' => $this->firstPeriod->id, 'classification' => 'regular',
        ])->assertCreated()->assertJsonPath('data.admission_applicant_id', $applicant->id)->json('data');
        $enrollmentApplication = $this->enrollmentAction($this->guest->fresh(), $enrollmentApplication, 'submit');
        $enrollmentApplication = $this->enrollmentAction($this->registrar, $enrollmentApplication, 'review');
        $enrollmentApplication = $this->enrollmentAction($this->registrar, $enrollmentApplication, 'approve');

        $this->authenticateAs($this->registrar, 'desktop');
        $enrollmentApplication = $this->academicAction($enrollmentApplication, 'subjects', ['subject_ids' => []]);
        $enrollmentApplication = $this->academicAction($enrollmentApplication, 'assign', ['section_id' => $this->section->id]);
        $finalized = $this->postJson('/api/enrollment/academic/applications/'.$enrollmentApplication['id'].'/finalize', [
            'version' => $enrollmentApplication['version'], 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.status', 'enrolled')->json('data');
        $this->postJson('/api/enrollment/academic/applications/'.$enrollmentApplication['id'].'/finalize', [
            'version' => $enrollmentApplication['version'], 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.enrollment_id', $finalized['enrollment_id']);
        $this->assertSame(
            1,
            Enrollment::where('student_id', $finalized['student_id'])
                ->where('academic_year_id', $this->year->id)
                ->where('semester_id', $this->firstSemester->id)
                ->count(),
        );
        $student = Student::findOrFail($finalized['student_id']);
        $this->assertSame($this->guest->id, $student->user_id);
        $this->assertSame(Role::STUDENT, $this->guest->fresh('role')->role->role_name);
        $this->assertDatabaseHas('enrollments', [
            'id' => $finalized['enrollment_id'], 'student_id' => $student->id,
            'section_id' => $this->section->id, 'academic_year_id' => $this->year->id,
            'semester_id' => $this->firstSemester->id,
        ]);
        $this->assertSame(
            collect($this->initialSubjects)->pluck('id')->sort()->values()->all(),
            EnrollmentSubject::where('enrollment_id', $finalized['enrollment_id'])->pluck('subject_id')->sort()->values()->all(),
        );

        $this->getJson('/api/students?search='.$student->student_number)->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.has_admission_record', true);
        $this->getJson('/api/students/'.$student->id.'/history')->assertOk()
            ->assertJsonPath('data.entry_classification', 'Freshman')
            ->assertJsonPath('data.admission.applicant_number', $applicant->applicant_number)
            ->assertJsonPath('data.admission.provenance', 'enrollment_finalization')
            ->assertJsonPath('data.applications.0.id', $enrollmentApplication['id'])
            ->assertJsonPath('data.academic_history.0.id', $finalized['enrollment_id']);

        [$passingSheet, $failingSheet] = $this->professorGrades($student);
        $this->publishGrades([$passingSheet, $failingSheet]);

        $completed = EnrollmentSubject::where('enrollment_id', $finalized['enrollment_id'])
            ->get()->keyBy('subject_id');
        $this->assertSame('completed', $completed[$this->initialSubjects[0]->id]->subject_status);
        $this->assertSame('Passed', $completed[$this->initialSubjects[0]->id]->remarks);
        $this->assertSame('completed', $completed[$this->initialSubjects[1]->id]->subject_status);
        $this->assertSame('Failed', $completed[$this->initialSubjects[1]->id]->remarks);

        $this->authenticateAs($this->guest->fresh(), 'web');
        $this->getJson('/api/grading/student/grades')->assertOk()
            ->assertJsonCount(2, 'data.grades')
            ->assertJsonPath('data.grades.0.subject_code', $this->initialSubjects[0]->subject_code)
            ->assertJsonPath('data.grades.1.subject_code', $this->initialSubjects[1]->subject_code);
        $otherStudent = $this->unrelatedStudent();
        $this->authenticateAs($otherStudent->user, 'web');
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.grades');
        $this->getJson('/api/students/'.$student->id)->assertForbidden();

        [$laterApplication, $allowedSubject, $blockedSubject] = $this->laterEnrollment($student);
        $this->authenticateAs($this->guest->fresh(), 'web');
        $allowed = $this->postJson('/api/enrollment/academic/applications/'.$laterApplication['id'].'/subjects', [
            'version' => $laterApplication['version'], 'subject_ids' => [$allowedSubject->id],
        ])->assertOk()->json('data');
        $this->assertDatabaseHas('enrollment_application_subjects', [
            'application_id' => $laterApplication['id'],
            'subject_id' => $allowedSubject->id,
        ]);
        $this->postJson('/api/enrollment/academic/applications/'.$laterApplication['id'].'/subjects', [
            'version' => $allowed['version'], 'subject_ids' => [$blockedSubject->id],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('enrollment_application_subjects', [
            'application_id' => $laterApplication['id'], 'subject_id' => $allowedSubject->id,
        ]);
        $this->assertDatabaseMissing('enrollment_application_subjects', [
            'application_id' => $laterApplication['id'], 'subject_id' => $blockedSubject->id,
        ]);

        $documentType = DocumentType::create([
            'document_name' => 'Lifecycle Transcript', 'processing_fee' => 0,
            'processing_days' => 1, 'requires_appointment' => false,
            'student_requestable' => true, 'student_uploadable' => false, 'status' => 'active',
        ]);
        $documentRequest = $this->postJson('/api/document-requests', [
            'document_type_id' => $documentType->id, 'quantity' => 1,
            'purpose_category' => 'Evaluation',
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('document_requests', [
            'id' => $documentRequest['id'], 'student_id' => $student->id,
        ]);
        $this->authenticateAs($otherStudent->user, 'web');
        $this->getJson('/api/document-requests/'.$documentRequest['id'])->assertForbidden();

        $this->authenticateAs($this->registrar, 'desktop');
        $this->getJson('/api/students/'.$student->id.'/history')->assertOk()
            ->assertJsonPath('data.entry_classification', 'Freshman')
            ->assertJsonPath('data.admission.applicant_number', $applicant->applicant_number)
            ->assertJsonCount(2, 'data.applications');
        $this->getJson('/api/registrar/document-requests/'.$documentRequest['id'])->assertOk();
    }

    private function professorGrades(Student $student): array
    {
        GradePeriodSchedule::create([
            'academic_year_id' => $this->year->id, 'semester_id' => $this->firstSemester->id,
            'midterm_opens_at' => now()->subDays(10), 'midterm_deadline' => now()->addDays(5),
            'finals_opens_at' => now()->subDay(), 'finals_deadline' => now()->addDays(5),
            'created_by' => $this->registrar->id, 'updated_by' => $this->registrar->id,
        ]);
        GradingScale::create([
            'min_percentage' => 0, 'max_percentage' => 74.99,
            'grade_point' => 5, 'remark' => 'FAILED', 'is_active' => true,
        ]);
        GradingScale::create([
            'min_percentage' => 75, 'max_percentage' => 100,
            'grade_point' => 1, 'remark' => 'PASSED', 'is_active' => true,
        ]);

        $this->authenticateAs($this->professorUser, 'web');
        $classes = $this->getJson('/api/grading/classes')->assertOk()
            ->assertJsonPath('data.total', 2)->json('data.data');
        $this->assertSame(1, $classes[0]['student_count']);

        $otherProfessorUser = $this->user(Role::PROFESSOR, 'Other', 'Professor');
        Professor::create([
            'user_id' => $otherProfessorUser->id,
            'user_profile_id' => $otherProfessorUser->profile->id,
            'department_id' => $this->professor->department_id,
            'employee_number' => 'LC-PROF-002',
            'status' => 'active',
        ]);
        $this->authenticateAs($otherProfessorUser, 'web');
        $this->postJson('/api/grading/classes/'.$this->assignments[0]->id.'/workspace')->assertNotFound();
        $this->authenticateAs($this->professorUser, 'web');

        $sheets = [];
        foreach ($this->assignments as $index => $assignment) {
            $workspace = $this->postJson('/api/grading/classes/'.$assignment->id.'/workspace')
                ->assertOk()->assertJsonPath('data.roster.0.student_id', $student->id)->json('data');
            $sheet = GradeSheet::findOrFail($workspace['sheet']['id']);
            $enrollmentSubjectId = $workspace['roster'][0]['enrollment_subject_id'];
            $score = $index === 0 ? 90 : 50;
            foreach (['midterm', 'finals'] as $period) {
                $assessments = collect(['Quiz', 'Activity', 'Recitation', 'Major Exam'])->map(
                    fn (string $category, int $order): array => [
                        'label' => ucfirst($period).' '.$category,
                        'category' => $category,
                        'max_score' => 100,
                        'display_order' => $order + 1,
                    ],
                )->all();
                $this->postJson('/api/grading/sheets/'.$sheet->id.'/assessments/bulk', [
                    'period' => $period, 'assessments' => $assessments,
                ])->assertCreated();
            }
            foreach (GradeAssessment::where('grade_sheet_id', $sheet->id)->get() as $assessment) {
                $this->putJson('/api/grading/sheets/'.$sheet->id.'/scores', [
                    'assessment_id' => $assessment->id,
                    'scores' => [['enrollment_subject_id' => $enrollmentSubjectId, 'score' => $score]],
                ])->assertOk();
            }
            $this->postJson('/api/grading/sheets/'.$sheet->id.'/submit', [
                'version' => $sheet->fresh()->version,
            ])->assertOk();
            $this->postJson('/api/grading/sheets/'.$sheet->id.'/submit', [
                'version' => $sheet->fresh()->version,
            ])->assertConflict();
            $this->assertSame(1, $sheet->submissions()->count());
            $sheets[] = $sheet->fresh();
        }

        $this->authenticateAs($this->guest->fresh(), 'web');
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.grades');

        return $sheets;
    }

    /** @param array<int, GradeSheet> $sheets */
    private function publishGrades(array $sheets): void
    {
        $this->authenticateAs($this->registrar, 'desktop');
        $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        foreach ($sheets as $sheet) {
            $this->postJson('/api/grading/reviews/'.$sheet->id.'/approve', [
                'version' => $sheet->fresh()->version,
            ])->assertOk();
        }
        $this->authenticateAs($this->guest->fresh(), 'web');
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.grades');

        $this->authenticateAs($this->registrar, 'desktop');
        $release = $this->postJson('/api/grading/releases', [
            'academic_year_id' => $this->year->id,
            'semester_id' => $this->firstSemester->id,
            'release_at' => now()->subMinute()->toIso8601String(),
            'grade_sheet_ids' => collect($sheets)->pluck('id')->all(),
        ])->assertCreated()->json('data');
        $this->postJson('/api/grading/releases/'.$release['id'].'/execute')->assertOk();
        $publishedEvents = GradeAuditEvent::where('action', 'grade_sheet.published')->count();
        $publishedVersions = collect($sheets)->mapWithKeys(
            fn (GradeSheet $sheet): array => [$sheet->id => $sheet->fresh()->version],
        );
        app(GradingWorkflowService::class)->executeRelease(
            GradeReleaseSchedule::findOrFail($release['id']),
            $this->registrar,
            true,
        );
        $this->assertSame($publishedEvents, GradeAuditEvent::where('action', 'grade_sheet.published')->count());
        foreach ($publishedVersions as $sheetId => $version) {
            $this->assertSame($version, GradeSheet::findOrFail($sheetId)->version);
        }
    }

    private function laterEnrollment(Student $student): array
    {
        $this->firstPeriod->update(['enabled' => false]);
        $this->firstSemester->update(['status' => 'inactive']);
        $laterSemester = Semester::create([
            'semester_name' => 'Lifecycle Second Semester', 'semester_order' => 2, 'status' => 'active',
        ]);
        $laterPeriod = $this->period($laterSemester);
        $allowed = Subject::create([
            ...$this->subjectData($laterSemester, 'LC201', 'Lifecycle Advanced Foundations'),
            'prerequisite_subject_id' => $this->initialSubjects[0]->id,
        ]);
        $blocked = Subject::create([
            ...$this->subjectData($laterSemester, 'LC202', 'Lifecycle Advanced Programming'),
            'prerequisite_subject_id' => $this->initialSubjects[1]->id,
        ]);

        $this->authenticateAs($this->guest->fresh(), 'web');
        $application = $this->postJson('/api/enrollment/applications', [
            'period_id' => $laterPeriod->id, 'classification' => 'irregular',
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('enrollment_applications', [
            'id' => $application['id'],
            'student_id' => $student->id,
        ]);
        $application = $this->enrollmentAction($this->guest->fresh(), $application, 'submit');
        $application = $this->enrollmentAction($this->registrar, $application, 'review');
        $application = $this->enrollmentAction($this->registrar, $application, 'approve');

        return [$application, $allowed, $blocked];
    }

    private function enrollmentAction(User $actor, array $application, string $action): array
    {
        $this->authenticateAs($actor, in_array($actor->role?->role_name, [Role::REGISTRAR_STAFF, Role::ADMIN], true) ? 'desktop' : 'web');

        return $this->postJson('/api/enrollment/applications/'.$application['id'].'/'.$action, [
            'version' => $application['version'],
            'notes' => 'Lifecycle integration verification.',
            'confirmed' => true,
        ])->assertOk()->json('data');
    }

    private function academicAction(array $application, string $action, array $extra): array
    {
        return $this->postJson('/api/enrollment/academic/applications/'.$application['id'].'/'.$action, [
            'version' => $application['version'], 'confirmed' => true, ...$extra,
        ])->assertOk()->json('data');
    }

    private function authenticateAs(User $user, string $client): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-CDM-Client', $client);
    }

    private function user(string $role, string $firstName, string $lastName): User
    {
        $user = User::factory()->create([
            'role_id' => Role::where('role_name', $role)->value('id'),
        ]);
        $user->profile()->create([
            'first_name' => $firstName, 'last_name' => $lastName, 'gender' => 'Prefer not to say',
        ]);

        return $user;
    }

    private function questionBank(): void
    {
        foreach (ProgramMatcher::INTEREST_CATEGORIES as $topicIndex => $topic) {
            for ($index = 0; $index < 22; $index++) {
                AdmissionExamQuestion::create([
                    'question_code' => "LC-{$topicIndex}-{$index}", 'topic' => $topic,
                    'question_text' => "Lifecycle question {$topicIndex}-{$index}",
                    'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D',
                    'correct_answer' => 'A', 'difficulty' => 'easy', 'status' => 'active',
                    'version' => 1, 'created_by_user_id' => $this->admin->id,
                    'updated_by_user_id' => $this->admin->id,
                ]);
            }
        }
    }

    private function period(Semester $semester): EnrollmentPeriod
    {
        return EnrollmentPeriod::create([
            'academic_year_id' => $this->year->id, 'semester_id' => $semester->id,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'enabled' => true,
            'document_requirements' => [
                'regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => [],
            ],
            'created_by' => $this->registrar->id, 'updated_by' => $this->registrar->id,
        ]);
    }

    private function subjectData(Semester $semester, string $code, string $name): array
    {
        return [
            'curriculum_id' => $this->curriculum->id, 'semester_id' => $semester->id,
            'subject_code' => $code, 'subject_name' => $name, 'year_level' => 1,
            'units' => 3, 'lecture_hours' => 3, 'laboratory_hours' => 0, 'status' => 'active',
        ];
    }

    private function unrelatedStudent(): Student
    {
        $user = $this->user(Role::STUDENT, 'Unrelated', 'Student');

        return Student::create([
            'user_id' => $user->id, 'user_profile_id' => $user->profile->id,
            'course_id' => $this->course->id, 'curriculum_id' => $this->curriculum->id,
            'student_number' => 'LC-OTHER-001', 'admission_date' => today(),
            'year_level' => 1, 'student_status' => 'regular',
        ]);
    }
}

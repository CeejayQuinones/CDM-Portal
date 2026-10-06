<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeMessage;
use App\Models\GradePeriodSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class GradingPhaseThreeTest extends TestCase
{
    private array $f;

    private GradeSheet $sheet;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->f = EnrollmentAcademicFixture::create('grading-three');
        $assignment = SectionSubject::create(['section_id' => $this->f['section']->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id]);
        $this->enrollment = Enrollment::create(['student_id' => $this->f['student']->id, 'section_id' => $this->f['section']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'enrollment_date' => today(), 'status' => 'enrolled']);
        $enrollmentSubject = EnrollmentSubject::create(['enrollment_id' => $this->enrollment->id, 'subject_id' => $this->f['subjects'][0]->id, 'professor_id' => $this->f['professor']->id, 'subject_status' => 'enrolled']);
        Sanctum::actingAs($this->f['professorUser']);
        $this->postJson('/api/grading/classes/'.$assignment->id.'/workspace')->assertOk();
        $this->sheet = GradeSheet::where('section_subject_id', $assignment->id)->firstOrFail();
        GradePeriodSchedule::create(['academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'midterm_opens_at' => now()->subDays(10), 'midterm_deadline' => now()->subDays(6), 'finals_opens_at' => now()->subDays(5), 'finals_deadline' => now()->subDay(), 'created_by' => $this->f['registrar']->id, 'updated_by' => $this->f['registrar']->id]);
        $scores = ['midterm' => 80, 'finals' => 90];
        $order = 1;
        foreach ($scores as $period => $score) {
            foreach (['Quiz', 'Activity', 'Recitation', 'Major Exam'] as $category) {
                $assessment = GradeAssessment::create(['grade_sheet_id' => $this->sheet->id, 'period' => $period, 'label' => $period.' '.$category, 'category' => $category, 'max_score' => 100, 'display_order' => $order++]);
                GradeScore::create(['grade_assessment_id' => $assessment->id, 'enrollment_subject_id' => $enrollmentSubject->id, 'score' => $score, 'updated_by' => $this->f['professorUser']->id]);
            }
        }
    }

    private function submit(): void
    {
        Sanctum::actingAs($this->f['professorUser']);
        $this->postJson('/api/grading/sheets/'.$this->sheet->id.'/submit', ['version' => $this->sheet->fresh()->version])->assertOk();
    }

    private function publish(): void
    {
        $this->submit();
        $this->sheet->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $this->f['registrar']->id, 'version' => $this->sheet->version + 2])->save();
    }

    private function student(): void
    {
        Sanctum::actingAs($this->f['user']);
    }

    public function test_student_sees_only_own_published_snapshot(): void
    {
        $this->submit();
        $this->student();
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.grades');
        $this->sheet->update(['status' => 'approved']);
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.grades');
        $this->sheet->update(['status' => 'published', 'published_at' => now()]);
        $this->getJson('/api/grading/student/grades')->assertOk()
            ->assertJsonPath('data.grades.0.subject_code', $this->f['subjects'][0]->subject_code)
            ->assertJsonPath('data.grades.0.final_grade', 86)
            ->assertJsonPath('data.grades.0.grade_point', null)
            ->assertJsonPath('data.grades.0.remarks', null);

        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $otherUser->profile()->create(['first_name' => 'Other', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        Student::create(['user_id' => $otherUser->id, 'user_profile_id' => $profile->id, 'course_id' => $this->f['course']->id, 'curriculum_id' => $this->f['curriculum']->id, 'student_number' => 'OTHER-300', 'admission_date' => today(), 'year_level' => 1, 'student_status' => 'regular']);
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/grading/student/grades')->assertOk()->assertJsonCount(0, 'data.grades');
    }

    public function test_historical_context_survives_later_subject_section_and_professor_changes(): void
    {
        $originalCode = $this->f['subjects'][0]->subject_code;
        $originalSection = $this->f['section']->section_name;
        $this->publish();
        $otherUser = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        $profile = $otherUser->profile()->create(['first_name' => 'Replacement', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        $otherProfessor = Professor::create(['user_id' => $otherUser->id, 'user_profile_id' => $profile->id, 'department_id' => $this->f['department']->id, 'employee_number' => 'REPLACEMENT-3', 'status' => 'active']);
        $this->f['subjects'][0]->update(['subject_code' => 'CHANGED', 'subject_name' => 'Changed title']);
        $this->f['section']->update(['section_name' => 'CHANGED-SECTION']);
        $this->sheet->sectionSubject()->update(['professor_id' => $otherProfessor->id]);
        $laterSection = Section::create(['course_id' => $this->f['course']->id, 'academic_year_id' => $this->f['year']->id, 'semester_id' => $this->f['semester']->id, 'section_name' => 'LATER-SECTION', 'year_level' => 1, 'capacity' => 30, 'status' => 'open']);
        $this->enrollment->update(['section_id' => $laterSection->id]);
        $this->student();
        $this->getJson('/api/grading/student/grades')->assertOk()
            ->assertJsonPath('data.grades.0.subject_code', $originalCode)
            ->assertJsonPath('data.grades.0.section', $originalSection)
            ->assertJsonPath('data.grades.0.professor', 'Academic Professor')
            ->assertJsonPath('data.grades.0.historical_context', 'snapshot');
    }

    public function test_gwa_is_unavailable_and_partial_publication_is_clearly_incomplete(): void
    {
        $this->publish();
        EnrollmentSubject::create(['enrollment_id' => $this->enrollment->id, 'subject_id' => $this->f['subjects'][1]->id, 'professor_id' => $this->f['professor']->id, 'subject_status' => 'enrolled']);
        $this->student();
        $this->getJson('/api/grading/student/grades')->assertOk()
            ->assertJsonPath('data.summary.completion', 'incomplete')
            ->assertJsonPath('data.summary.published_subjects', 1)
            ->assertJsonPath('data.summary.expected_subjects', 2)
            ->assertJsonPath('data.summary.gwa.available', false)
            ->assertJsonPath('data.summary.gwa.value', null)
            ->assertJsonPath('data.summary.gwa.message', 'Not available until grading scale is configured.');
    }

    public function test_student_and_professor_can_message_with_server_read_state_and_ownership(): void
    {
        $this->publish();
        $this->student();
        $conversation = $this->postJson('/api/grading/student/grades/'.$this->sheet->id.'/conversation')->assertCreated()->json('data');
        $message = $this->postJson('/api/grading/conversations/'.$conversation['id'].'/messages', ['body' => 'Please clarify my final grade.'])->assertCreated()->json('data');
        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson('/api/grading/professor/conversations')->assertOk()->assertJsonPath('data.0.unread_count', 1);
        $this->getJson('/api/grading/conversations/'.$conversation['id'])->assertOk()->assertJsonPath('data.messages.0.body', 'Please clarify my final grade.');
        $this->assertNotNull(GradeMessage::find($message['id'])->read_at);
        $reply = $this->postJson('/api/grading/conversations/'.$conversation['id'].'/messages', ['body' => 'I will review the published computation.'])->assertCreated()->json('data');
        $this->deleteJson('/api/grading/messages/'.$message['id'])->assertForbidden();
        $this->deleteJson('/api/grading/messages/'.$reply['id'])->assertOk()->assertJsonPath('data.unsent_at', fn ($value) => $value !== null);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_message.read']);
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_message.unsent']);
    }

    public function test_non_published_and_foreign_professor_message_access_is_blocked(): void
    {
        $this->submit();
        $this->student();
        $this->postJson('/api/grading/student/grades/'.$this->sheet->id.'/conversation')->assertNotFound();
        $this->sheet->update(['status' => 'published', 'published_at' => now()]);
        $conversation = $this->postJson('/api/grading/student/grades/'.$this->sheet->id.'/conversation')->assertCreated()->json('data');
        $foreign = User::factory()->create(['role_id' => Role::where('role_name', Role::PROFESSOR)->value('id')]);
        Sanctum::actingAs($foreign);
        $this->getJson('/api/grading/conversations/'.$conversation['id'])->assertNotFound();
    }

    public function test_message_attachment_is_private_validated_and_removed_on_unsend(): void
    {
        Storage::fake('local');
        $this->publish();
        $this->student();
        $conversation = $this->postJson('/api/grading/student/grades/'.$this->sheet->id.'/conversation')->assertCreated()->json('data');
        $this->post('/api/grading/conversations/'.$conversation['id'].'/messages', ['attachment' => UploadedFile::fake()->create('unsafe.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $message = $this->post('/api/grading/conversations/'.$conversation['id'].'/messages', ['attachment' => UploadedFile::fake()->image('evidence.png', 20, 20)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $row = GradeMessage::findOrFail($message['id']);
        Storage::disk('local')->assertExists($row->attachment_path);
        $this->get('/api/grading/messages/'.$row->id.'/attachment')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $path = $row->attachment_path;
        $this->deleteJson('/api/grading/messages/'.$row->id)->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_student_staff_and_admin_csv_exports_are_authorized_and_safe(): void
    {
        $this->publish();
        $this->student();
        $studentCsv = $this->get('/api/grading/student/grades/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Academic Grade Summary', $studentCsv);
        $this->assertStringNotContainsString('checksum', strtolower($studentCsv));
        $this->assertStringNotContainsString('password', strtolower($studentCsv));
        $this->get('/api/grading/staff/sheets/'.$this->sheet->id.'/export')->assertForbidden();
        Sanctum::actingAs($this->f['registrar']);
        $this->get('/api/grading/staff/sheets/'.$this->sheet->id.'/export')->assertOk()->assertDownload();
        $this->get('/api/grading/staff/students/'.$this->f['student']->id.'/export')->assertOk()->assertDownload();
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::ADMIN])->id]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-CDM-Client', 'web')->get('/api/grading/staff/sheets/'.$this->sheet->id.'/export')->assertOk();
        $this->assertDatabaseHas('grade_audit_events', ['action' => 'grade_report.exported']);
    }

    public function test_roles_and_platform_policy_protect_student_grade_endpoints(): void
    {
        $this->publish();
        $guest = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::GUEST])->id]);
        Sanctum::actingAs($guest);
        $this->getJson('/api/grading/student/grades')->assertForbidden();
        Sanctum::actingAs($this->f['professorUser']);
        $this->getJson('/api/grading/student/grades')->assertForbidden();
        $this->student();
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/grading/student/grades')->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/grading/student/grades')->assertForbidden();
    }
}

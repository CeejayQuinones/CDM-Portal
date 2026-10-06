<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventAttendanceSession;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventReportsTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->fixture = $this->fixture();
    }

    public function test_coordinator_and_semi_coordinator_can_view_correct_summary_filters_and_csv_without_secrets(): void
    {
        foreach ([$this->fixture['admin'], $this->fixture['registrar']] as $staff) {
            Sanctum::actingAs($staff);
            $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/event-reports/'.$this->fixture['event']->id)
                ->assertOk()->assertJsonPath('data.summary.eligible', 3)->assertJsonPath('data.summary.present', 1)
                ->assertJsonPath('data.summary.late', 1)->assertJsonPath('data.summary.excused', 0)
                ->assertJsonPath('data.summary.absent', 1)->assertJsonPath('data.summary.attendance_rate', 66.7)
                ->assertJsonCount(3, 'data.students.data');
        }

        Sanctum::actingAs($this->fixture['admin']);
        $this->getJson('/api/event-reports/'.$this->fixture['event']->id.'?status=late&course_id='.$this->fixture['course'].'&year_level=1&section_id='.$this->fixture['section'].'&search=0002')
            ->assertOk()->assertJsonCount(1, 'data.students.data')->assertJsonPath('data.students.data.0.status', 'late');
        $response = $this->get('/api/event-reports/'.$this->fixture['event']->id.'/export?format=csv&status=present')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Event Attendance Report', $csv);
        $this->assertStringContainsString('2026-0001', $csv);
        $this->assertStringNotContainsString('2026-0002', $csv);
        $this->assertStringNotContainsString('qr_token_fingerprint', $csv);
        $this->assertStringNotContainsString('token_version', $csv);
        $this->assertDatabaseHas('event_audit_events', ['event_id' => $this->fixture['event']->id, 'action' => 'report.generated']);
        $this->assertDatabaseHas('event_audit_events', ['event_id' => $this->fixture['event']->id, 'action' => 'report.exported']);
    }

    public function test_report_authorization_and_platform_policy_are_enforced(): void
    {
        foreach ([Role::STUDENT, Role::PROFESSOR, Role::GUEST] as $role) {
            Sanctum::actingAs($role === Role::STUDENT ? $this->fixture['students'][0]->user : $this->user($role));
            $this->getJson('/api/event-reports/'.$this->fixture['event']->id)->assertForbidden();
            $this->get('/api/event-reports/'.$this->fixture['event']->id.'/export?format=csv')->assertForbidden();
        }
        Sanctum::actingAs($this->fixture['registrar']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/event-reports')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-reports')->assertForbidden();
        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->getJson('/api/event-reports')->assertOk();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-reports')->assertForbidden();
    }

    public function test_student_sees_only_own_attendance_on_event_detail(): void
    {
        Sanctum::actingAs($this->fixture['students'][0]->user);
        $this->getJson('/api/events/'.$this->fixture['event']->id)->assertOk()
            ->assertJsonPath('data.my_attendance.status', 'present')
            ->assertJsonMissingPath('data.students')->assertJsonMissingPath('data.attendances');
        Sanctum::actingAs($this->fixture['students'][2]->user);
        $this->getJson('/api/events/'.$this->fixture['event']->id)->assertOk()->assertJsonPath('data.my_attendance.status', 'not_recorded');
    }

    public function test_attendance_keeps_historical_academic_snapshot_after_student_changes(): void
    {
        $student = $this->fixture['students'][0];
        $this->assertDatabaseHas('event_attendances', [
            'student_id' => $student->id, 'course_id_at_attendance' => $this->fixture['course'],
            'year_level_at_attendance' => 1, 'section_id_at_attendance' => $this->fixture['section'],
        ]);
        $student->forceFill(['course_id' => $this->fixture['other_course'], 'year_level' => 2])->save();
        Sanctum::actingAs($this->fixture['admin']);
        $this->getJson('/api/event-reports/'.$this->fixture['event']->id.'?status=present')->assertOk()
            ->assertJsonPath('data.students.data.0.course', 'BSIT')->assertJsonPath('data.students.data.0.year_level', 1)->assertJsonPath('data.students.data.0.section', 'BSIT-1A');
    }

    public function test_one_level_sub_events_inherit_audience_reuse_attendance_and_aggregate_unique_students(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $parent = $this->futureEvent('Foundation Day');
        $child = $this->postJson('/api/events', $this->payload([
            'parent_event_id' => $parent->id, 'title' => 'Opening Ceremony', 'venue' => 'Main Hall', 'audiences' => [],
            'starts_at' => '2026-10-10 09:00:00', 'ends_at' => '2026-10-10 10:00:00', 'intent' => 'publish',
        ]))->assertCreated()->assertJsonPath('data.audience_inherited', true)->json('data');
        $this->assertDatabaseHas('event_audit_events', ['event_id' => $child['id'], 'action' => 'subevent.created']);

        $this->postJson('/api/events', $this->payload([
            'parent_event_id' => $child['id'], 'title' => 'Invalid Grandchild', 'audiences' => [],
            'starts_at' => '2026-10-10 09:10:00', 'ends_at' => '2026-10-10 09:20:00',
        ]))->assertUnprocessable();
        $this->postJson('/api/events', $this->payload([
            'parent_event_id' => $parent->id, 'title' => 'Outside Parent', 'audiences' => [],
            'starts_at' => '2026-10-10 07:00:00', 'ends_at' => '2026-10-10 08:00:00',
        ]))->assertUnprocessable();

        $childModel = Event::query()->findOrFail($child['id']);
        $session = EventAttendanceSession::query()->create(['event_id' => $childModel->id, 'opened_by' => $this->fixture['admin']->id, 'opened_at' => now(), 'status' => 'closed', 'closed_at' => now()]);
        EventAttendance::query()->create($this->attendanceData($childModel, $session, $this->fixture['students'][0], 'present'));
        EventAttendance::query()->create($this->attendanceData($childModel, $session, $this->fixture['students'][1], 'late'));
        $second = Event::query()->create(['parent_event_id' => $parent->id, 'title' => 'Closing Ceremony', 'venue' => 'Main Hall', 'venue_key' => 'main hall', 'starts_at' => '2026-10-10 16:00:00', 'ends_at' => '2026-10-10 17:00:00', 'status' => 'published', 'created_by' => $this->fixture['admin']->id, 'updated_by' => $this->fixture['admin']->id]);
        $secondSession = EventAttendanceSession::query()->create(['event_id' => $second->id, 'opened_by' => $this->fixture['admin']->id, 'opened_at' => now(), 'status' => 'closed', 'closed_at' => now()]);
        EventAttendance::query()->create($this->attendanceData($second, $secondSession, $this->fixture['students'][0], 'present'));

        $this->getJson('/api/event-reports/'.$parent->id)->assertOk()->assertJsonCount(2, 'data.sub_events.items')->assertJsonPath('data.sub_events.overall_unique_attendees', 2);
        Sanctum::actingAs($this->fixture['students'][2]->user);
        $this->getJson('/api/events/'.$childModel->id)->assertOk()->assertJsonPath('data.audience_inherited', true);
    }

    private function fixture(): array
    {
        $admin = $this->user(Role::ADMIN);
        $registrar = $this->user(Role::PROFESSOR);
        $department = DB::table('departments')->insertGetId(['department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = $this->course($department, 'BSIT');
        $otherCourse = $this->course($department, 'BSCS');
        $year = DB::table('academic_years')->insertGetId(['school_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $semester = DB::table('semesters')->insertGetId(['semester_name' => 'First Semester', 'semester_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $section = DB::table('sections')->insertGetId(['course_id' => $course, 'academic_year_id' => $year, 'semester_id' => $semester, 'section_name' => 'BSIT-1A', 'year_level' => 1, 'capacity' => 40, 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $students = collect(['0001', '0002', '0003'])->map(fn ($number) => $this->student($course, $section, $year, $semester, $number))->all();
        $event = Event::query()->create(['title' => 'General Assembly', 'venue' => 'Gym', 'venue_key' => 'gym', 'starts_at' => '2026-10-03 09:00:00', 'ends_at' => '2026-10-03 12:00:00', 'status' => 'published', 'created_by' => $admin->id, 'updated_by' => $admin->id]);
        $event->audiences()->create(['audience_type' => 'section', 'section_id' => $section]);
        $session = EventAttendanceSession::query()->create(['event_id' => $event->id, 'opened_by' => $admin->id, 'opened_at' => now(), 'status' => 'closed', 'closed_at' => now()]);
        EventAttendance::query()->create($this->attendanceData($event, $session, $students[0], 'present'));
        EventAttendance::query()->create($this->attendanceData($event, $session, $students[1], 'late'));

        EventRoleAssignment::query()->create([
            'event_id' => $event->id,
            'user_id' => $registrar->id,
            'responsibility' => EventRoleAssignment::SEMI_COORDINATOR,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        return compact('admin', 'registrar', 'course', 'otherCourse', 'year', 'semester', 'section', 'students', 'event') + ['other_course' => $otherCourse];
    }

    private function attendanceData(Event $event, EventAttendanceSession $session, Student $student, string $status): array
    {
        return ['event_id' => $event->id, 'attendance_session_id' => $session->id, 'student_id' => $student->id, 'course_id_at_attendance' => $student->course_id, 'year_level_at_attendance' => 1, 'section_id_at_attendance' => DB::table('enrollments')->where('student_id', $student->id)->value('section_id'), 'status' => $status, 'checked_in_at' => now(), 'source' => 'qr', 'recorded_by' => $student->user_id];
    }

    private function futureEvent(string $title): Event
    {
        $event = Event::query()->create(['title' => $title, 'venue' => 'Campus', 'venue_key' => 'campus', 'starts_at' => '2026-10-10 08:00:00', 'ends_at' => '2026-10-10 18:00:00', 'status' => 'published', 'created_by' => $this->fixture['admin']->id, 'updated_by' => $this->fixture['admin']->id]);
        $event->audiences()->create(['audience_type' => 'section', 'section_id' => $this->fixture['section']]);

        return $event;
    }

    private function payload(array $overrides): array
    {
        return array_replace(['title' => 'Sub-event', 'description' => null, 'venue' => 'Campus', 'starts_at' => '2026-10-10 09:00:00', 'ends_at' => '2026-10-10 10:00:00', 'intent' => 'draft', 'audiences' => [['audience_type' => 'all_students']]], $overrides);
    }

    private function user(string $role): User
    {
        $model = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::query()->create(['username' => 'report_user_'.(++$this->sequence), 'password' => 'password', 'role_id' => $model->id, 'status' => 'active', 'is_first_login' => false]);
    }

    private function course(int $department, string $code): int
    {
        return DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => $code, 'course_name' => $code, 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function student(int $course, int $section, int $year, int $semester, string $number): Student
    {
        $user = $this->user(Role::STUDENT);
        $profile = DB::table('user_profiles')->insertGetId(['user_id' => $user->id, 'first_name' => 'Student', 'last_name' => $number, 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'CUR-'.$number, 'curriculum_name' => 'Curriculum '.$number, 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $student = Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => '2026-'.$number, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular']);
        DB::table('enrollments')->insert(['student_id' => $student->id, 'section_id' => $section, 'academic_year_id' => $year, 'semester_id' => $semester, 'enrollment_date' => '2026-08-01', 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]);

        return $student;
    }
}

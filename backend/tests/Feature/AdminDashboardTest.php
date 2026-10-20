<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Event;
use App\Models\EventAttendanceSession;
use App\Models\EventModeratorRequest;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_receives_real_aggregates_for_the_current_term(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $admin = $this->user(Role::ADMIN);
        $studentUser = $this->user(Role::STUDENT);
        $student = $this->student($studentUser);
        $this->professor($this->user(Role::PROFESSOR));
        $this->user(Role::REGISTRAR_STAFF);
        $guest = $this->user(Role::GUEST);

        $year = AcademicYear::query()->create([
            'school_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'status' => 'active',
        ]);
        $semester = Semester::query()->create(['semester_name' => 'First Semester', 'semester_order' => 1, 'status' => 'active']);
        $period = EnrollmentPeriod::query()->create([
            'academic_year_id' => $year->id, 'semester_id' => $semester->id,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addMonth(), 'enabled' => true,
            'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []],
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $section = Section::query()->create([
            'course_id' => $student->course_id, 'academic_year_id' => $year->id, 'semester_id' => $semester->id,
            'section_name' => 'BSIT-1A', 'year_level' => 1, 'capacity' => 40, 'status' => 'open',
        ]);
        EnrollmentApplication::query()->create([
            'student_id' => $student->id, 'owner_user_id' => $studentUser->id, 'period_id' => $period->id,
            'academic_year_id' => $year->id, 'semester_id' => $semester->id, 'course_id' => $student->course_id,
            'curriculum_id' => $student->curriculum_id, 'year_level' => 1, 'classification' => 'regular', 'status' => 'submitted',
        ]);
        $olderStudentUser = $this->user(Role::STUDENT, 'inactive');
        $olderStudent = $this->student($olderStudentUser);
        $olderYear = AcademicYear::query()->create([
            'school_year' => '2025-2026', 'start_date' => '2025-08-01', 'end_date' => '2026-05-31', 'status' => 'completed',
        ]);
        $olderPeriod = EnrollmentPeriod::query()->create([
            'academic_year_id' => $olderYear->id, 'semester_id' => $semester->id,
            'opens_at' => now()->subYear(), 'closes_at' => now()->subMonths(6), 'enabled' => false,
            'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []],
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        EnrollmentApplication::query()->create([
            'student_id' => $olderStudent->id, 'owner_user_id' => $olderStudentUser->id, 'period_id' => $olderPeriod->id,
            'academic_year_id' => $olderYear->id, 'semester_id' => $semester->id, 'course_id' => $olderStudent->course_id,
            'curriculum_id' => $olderStudent->curriculum_id, 'year_level' => 1, 'classification' => 'regular', 'status' => 'submitted',
        ]);
        Enrollment::query()->create([
            'student_id' => $student->id, 'section_id' => $section->id, 'academic_year_id' => $year->id,
            'semester_id' => $semester->id, 'enrollment_date' => today(), 'status' => 'enrolled',
        ]);

        $cycle = AdmissionCycle::query()->create([
            'code' => 'ADM-2026', 'name' => 'Admission 2026', 'academic_year_id' => $year->id,
            'status' => AdmissionCycle::DRAFT, 'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id,
        ]);
        $applicant = new AdmissionApplicant;
        $applicant->forceFill([
            'user_id' => $guest->id, 'cycle_id' => $cycle->id, 'applicant_number' => 'APP-DASHBOARD',
            'status' => AdmissionApplicant::SUBMITTED, 'version' => 1,
        ])->save();

        $event = Event::query()->create([
            'title' => 'Current Event', 'venue' => 'Gym', 'venue_key' => 'gym',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2), 'status' => 'published',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        EventAttendanceSession::query()->create([
            'event_id' => $event->id, 'opened_by' => $admin->id, 'opened_at' => now(), 'status' => 'open',
        ]);
        EventModeratorRequest::query()->create([
            'event_id' => $event->id, 'requester_user_id' => $studentUser->id, 'status' => 'pending',
            'active_request_key' => EventModeratorRequest::activeKey($event->id, $studentUser->id),
            'request_reason' => 'Help with attendance.', 'requested_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Manila')
            ->assertJsonPath('data.summary.active_students', 1)
            ->assertJsonPath('data.summary.active_professors', 1)
            ->assertJsonPath('data.summary.registrar_staff_accounts', 1)
            ->assertJsonPath('data.summary.guest_accounts', 1)
            ->assertJsonPath('data.summary.pending_admission_applications', 1)
            ->assertJsonPath('data.summary.current_term_enrollments', 1)
            ->assertJsonPath('data.summary.pending_administrative_actions', 3)
            ->assertJsonPath('data.summary.published_or_ongoing_events', 1)
            ->assertJsonPath('data.enrollment.current_term.period_id', $period->id)
            ->assertJsonPath('data.enrollment.by_status.submitted', 1)
            ->assertJsonPath('data.events.items.0.attendance_session.status', 'open')
            ->assertJsonStructure(['data' => ['administrative' => ['pending_reviews', 'recent_activity']]]);
    }

    public function test_empty_operational_data_returns_stable_empty_structures(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.active_students', 0)
            ->assertJsonPath('data.summary.pending_administrative_actions', 0)
            ->assertJsonPath('data.enrollment.current_term', null)
            ->assertJsonPath('data.admissions.by_status', [])
            ->assertJsonCount(0, 'data.events.items')
            ->assertJsonCount(0, 'data.administrative.recent_activity');
    }

    public function test_non_admin_and_inactive_admin_accounts_cannot_access_dashboard(): void
    {
        foreach ([Role::REGISTRAR_STAFF, Role::STUDENT, Role::PROFESSOR, Role::GUEST] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admin/dashboard')->assertForbidden();
        }

        $inactiveAdmin = $this->user(Role::ADMIN, 'inactive');
        Sanctum::actingAs($inactiveAdmin);
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    private function user(string $role, string $status = 'active'): User
    {
        $record = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::factory()->create(['role_id' => $record->id, 'status' => $status]);
    }

    private function profile(User $user, string $prefix): UserProfile
    {
        return UserProfile::query()->create([
            'user_id' => $user->id, 'first_name' => $prefix, 'last_name' => 'Dashboard',
            'gender' => 'Prefer not to say', 'email' => strtolower($prefix).$user->id.'@example.test',
        ]);
    }

    private function student(User $user): Student
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1, 'department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $course = Course::query()->firstOrCreate(['course_code' => 'BSIT'], [
            'department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active',
        ]);
        $curriculum = Curriculum::query()->firstOrCreate(['curriculum_code' => 'BSIT-2026'], [
            'course_id' => $course->id, 'curriculum_name' => 'BSIT 2026', 'effective_year' => 2026, 'status' => 'active',
        ]);

        return Student::query()->create([
            'user_id' => $user->id, 'user_profile_id' => $this->profile($user, 'Student')->id,
            'course_id' => $course->id, 'curriculum_id' => $curriculum->id,
            'student_number' => '26-'.$user->id, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular',
        ]);
    }

    private function professor(User $user): Professor
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1, 'department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Professor::query()->create([
            'user_id' => $user->id, 'user_profile_id' => $this->profile($user, 'Professor')->id,
            'department_id' => 1, 'employee_number' => 'PROF-'.$user->id, 'status' => 'active',
        ]);
    }
}

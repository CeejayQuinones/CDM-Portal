<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admission\AdmissionExamPolicy;
use App\Services\Enrollment\EnrollmentEligibilityService;
use Database\Seeders\LargeDatasetSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalDevelopmentSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF, Role::STUDENT, Role::GUEST, Role::PROFESSOR] as $role) {
            Role::firstOrCreate(['role_name' => $role]);
        }
        User::factory()->create(['role_id' => Role::where('role_name', Role::ADMIN)->value('id'), 'status' => 'active']);
        AcademicYear::create(['school_year' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'status' => 'active']);
        $semester = Semester::create(['semester_name' => 'First', 'semester_order' => 1, 'status' => 'active']);
        $department = Department::create(['department_code' => 'ICS', 'department_name' => 'Institute of Computing', 'status' => 'active']);
        $course = Course::create(['department_id' => $department->id, 'course_code' => 'BSIT',
            'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active']);
        $curriculum = Curriculum::create(['course_id' => $course->id, 'curriculum_code' => 'BSIT-2026',
            'curriculum_name' => 'BSIT 2026', 'effective_year' => 2026, 'status' => 'active']);
        for ($level = 1; $level <= 4; $level++) {
            Subject::create(['curriculum_id' => $curriculum->id, 'semester_id' => $semester->id,
                'subject_code' => 'IT-'.$level, 'subject_name' => 'Test subject '.$level,
                'year_level' => $level, 'units' => 3, 'lecture_hours' => 3,
                'laboratory_hours' => 0, 'status' => 'active']);
        }
    }

    public function test_seed_is_repeatable_and_cleanup_is_scoped_to_its_ledger(): void
    {
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $this->assertSame(8, DB::table('students')->count());
        $this->assertSame(16, DB::table('sections')->count());
        $this->assertSame(4, DB::table('students')->distinct()->count('year_level'));
        $this->assertSame(1, DB::table('admission_applicants')->where('status', 'converted')->count());
        $this->assertSame(5, DB::table('admission_applicants')->count());
        $this->assertSame(400, DB::table('admission_exam_session_questions')->count());
        $this->assertSame(300, DB::table('admission_exam_answers')->count());
        $this->assertSame(1, DB::table('admission_recommendations')->count());
        $this->assertSame(8, DB::table('enrollment_applications')->count());
        $this->assertGreaterThan(0, DB::table('enrollments')->count());
        $this->artisan('portal:dev-seed')->assertExitCode(0);
        $this->assertSame(8, DB::table('students')->count());
        $this->artisan('portal:dev-seed', ['--cleanup' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('students')->count());
        $this->assertSame(0, DB::table('sections')->count());
        $this->assertSame(0, DB::table('generated_data_records')->count());
        $this->assertSame(1, DB::table('courses')->count());
        $this->assertSame(4, DB::table('subjects')->count());
    }

    public function test_staff_history_links_admission_and_enrollment_without_claiming_legacy_admission(): void
    {
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        Sanctum::actingAs(User::whereHas('role', fn ($q) => $q->where('role_name', Role::ADMIN))->firstOrFail());
        $converted = DB::table('students')->where('student_number', 'DEV-ENR-01')->value('id');
        $legacy = DB::table('students')->where('student_number', 'DEV-ENR-02')->value('id');
        $this->getJson('/api/students/'.$converted.'/history')->assertOk()
            ->assertJsonPath('data.entry_classification', 'Freshman')
            ->assertJsonPath('data.admission.status', 'converted')
            ->assertJsonPath('data.admission.result', 'PASSED');
        $this->getJson('/api/students/'.$legacy.'/history')->assertOk()
            ->assertJsonPath('data.admission', null)
            ->assertJsonPath('data.entry_classification', 'Continuing');
        $this->getJson('/api/students?search=DEV-ENR-01')->assertOk()
            ->assertJsonPath('data.0.admission_record', 'Available')
            ->assertJsonPath('data.0.enrollment_application_available', true);
        $this->getJson('/api/students?search=DEV-ENR-02')->assertOk()
            ->assertJsonPath('data.0.admission_record', 'No linked Admission record')
            ->assertJsonPath('data.0.enrollment_application_available', true);
        $registrar = User::factory()->create(['role_id' => Role::where('role_name', Role::REGISTRAR_STAFF)->value('id'), 'status' => 'active']);
        Sanctum::actingAs($registrar);
        $this->getJson('/api/students/'.$converted.'/history')->assertOk()
            ->assertJsonPath('data.admission.status', 'converted');
        DB::enableQueryLog();
        $this->getJson('/api/students')->assertOk()->assertJsonCount(8, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(14, $queries);
        Sanctum::actingAs(User::where('username', 'dev_enr_01')->firstOrFail());
        $this->getJson('/api/students/'.$legacy.'/history')->assertForbidden();
        Sanctum::actingAs(User::where('username', 'dev_enr_professor')->firstOrFail());
        $this->getJson('/api/students/'.$converted.'/history')->assertForbidden();
        Sanctum::actingAs(User::where('username', 'dev_enr_guest_draft')->firstOrFail());
        $this->getJson('/api/students/'.$converted.'/history')->assertForbidden();
    }

    public function test_existing_students_are_reused_without_admission_and_refresh_preserves_identity_and_sections(): void
    {
        $snapshots = [];
        foreach (range(1, 12) as $i) {
            $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id'), 'status' => 'active']);
            $profile = $user->profile()->create(['first_name' => 'Existing', 'last_name' => 'Student '.$i, 'gender' => 'Prefer not to say']);
            $student = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id,
                'course_id' => Course::first()->id, 'curriculum_id' => Curriculum::first()->id,
                'student_number' => 'EXISTING-'.$i, 'year_level' => ($i - 1) % 4 + 1,
                'admission_date' => now()->subYear()->toDateString(), 'student_status' => 'regular']);
            $snapshots[$student->id] = $student->fresh()->getAttributes();
        }
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $this->assertDatabaseCount('students', 13);
        $this->assertDatabaseCount('enrollment_applications', 13);
        foreach ($snapshots as $id => $snapshot) {
            $student = Student::findOrFail($id);
            $this->assertSame($snapshot, $student->getAttributes());
            $this->assertSame(1, $student->enrollmentApplications()->count());
            $this->assertSame(0, $student->user->admissionApplications()->count());
            $this->assertTrue(app(EnrollmentEligibilityService::class)->status($student->user)['academic_ready']);
        }
        $sectionIds = DB::table('sections')->orderBy('id')->pluck('id')->all();
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $this->assertDatabaseCount('students', 13);
        $this->assertDatabaseCount('enrollment_applications', 13);
        $this->assertSame(0, Artisan::call('portal:dev-seed', ['--refresh' => true]), Artisan::output());
        $this->assertSame($sectionIds, DB::table('sections')->orderBy('id')->pluck('id')->all());
        foreach ($snapshots as $id => $snapshot) {
            $this->assertSame($snapshot, Student::findOrFail($id)->getAttributes());
        }
        $this->assertSame(0, Artisan::call('portal:dev-seed', ['--cleanup' => true]), Artisan::output());
        $this->assertDatabaseCount('students', 12);
        $this->assertDatabaseCount('enrollment_applications', 0);
    }

    public function test_cleanup_refuses_untracked_enrollment_activity_and_rolls_back(): void
    {
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $application = EnrollmentApplication::firstOrFail();
        DB::table('generated_data_records')->where('record_type', 'application')->where('record_id', $application->id)->delete();
        $before = DB::table('generated_data_records')->count();
        $this->assertSame(1, Artisan::call('portal:dev-seed', ['--cleanup' => true]));
        $this->assertStringContainsString('cleanup refused', Artisan::output());
        $this->assertDatabaseCount('students', 8);
        $this->assertSame($before, DB::table('generated_data_records')->count());
        $this->assertDatabaseHas('enrollment_applications', ['id' => $application->id]);
    }

    public function test_seed_never_reuses_large_dataset_identities(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id'), 'status' => 'active']);
        $profile = $user->profile()->create(['first_name' => 'Large', 'last_name' => 'Fixture', 'gender' => 'Prefer not to say']);
        $student = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id,
            'course_id' => Course::first()->id, 'curriculum_id' => Curriculum::first()->id,
            'student_number' => 'LARGE-FIXTURE-1', 'year_level' => 1,
            'admission_date' => now()->subYear()->toDateString(), 'student_status' => 'regular']);
        DB::table('generated_data_records')->insert(['dataset_key' => LargeDatasetSeeder::LEGACY_DATASET_KEY,
            'record_type' => LargeDatasetSeeder::GENERATED_USER_RECORD_TYPE, 'record_id' => $user->id,
            'record_created_at' => $user->created_at, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $this->assertDatabaseMissing('enrollment_applications', ['student_id' => $student->id]);
        $this->assertSame(8, DB::table('enrollment_applications')->count());
    }

    public function test_refresh_preserves_protected_student_and_cleans_only_untouched_students(): void
    {
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $a = EnrollmentApplication::firstOrFail();
        DB::table('enrollment_workflow_events')->insert(['actor_user_id' => User::first()->id,
            'action' => 'enrollment.section_assigned', 'subject_type' => 'application',
            'subject_id' => $a->id, 'event_uuid' => (string) Str::uuid(), 'actor_role' => Role::ADMIN, 'metadata' => '{}', 'created_at' => now()]);
        $before = $a->fresh()->getAttributes();
        $this->assertSame(0, Artisan::call('portal:dev-seed', ['--refresh' => true]), Artisan::output());
        $this->assertSame($before, $a->fresh()->getAttributes());
        $this->assertDatabaseCount('students', 8);
        $this->assertDatabaseCount('sections', 16);
    }

    public function test_command_refuses_production(): void
    {
        $this->assertSame(0, Artisan::call('portal:dev-seed'), Artisan::output());
        $tracked = DB::table('generated_data_records')->count();
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->assertSame(1, Artisan::call('portal:dev-seed'));
            $this->assertSame($tracked, DB::table('generated_data_records')->count());
            $this->assertSame(0, AdmissionExamPolicy::bank()->where('question_code', 'like', 'DEV-MVP-ENR-%')->count());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}

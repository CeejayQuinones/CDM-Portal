<?php

namespace Tests\Feature;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Admission\AdmissionConversionService;
use App\Services\Enrollment\EnrollmentPeriodResolver;
use App\Services\Enrollment\EnrollmentWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdmissionConversionFixture;
use Tests\TestCase;

class EnrollmentFoundationTest extends TestCase
{
    private array $fixture;

    private array $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = AdmissionConversionFixture::create('enroll');
        $this->case = $this->fixture['cases'][0];
    }

    private function converted(): Student
    {
        $service = app(AdmissionConversionService::class);
        $applicant = $this->case['applicant'];
        $input = ['version' => $applicant->version, 'result_id' => $this->case['result']->id,
            'result_version' => $this->case['result']->version, 'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id, 'confirmed' => true];
        $service->accept($this->fixture['registrar'], $applicant->id, $input);
        $input['version'] = $applicant->fresh()->version;
        $service->convert($this->fixture['registrar'], $applicant->id, $input + ['student_number' => '26-ENROLL', 'admission_date' => now()->toDateString()]);
        Sanctum::actingAs($this->case['user']->fresh());

        return $this->case['user']->student()->firstOrFail();
    }

    private function window(?CarbonImmutable $opens = null, ?CarbonImmutable $closes = null): EnrollmentWindow
    {
        $semester = Semester::firstOrCreate(['semester_name' => 'First'], ['semester_order' => 1, 'status' => 'active']);
        $window = new EnrollmentWindow(1, $this->fixture['cycle']->academic_year_id, $semester->id,
            $opens ?? CarbonImmutable::now()->subDay(), $closes ?? CarbonImmutable::now()->addDay());
        $this->app->instance(EnrollmentPeriodResolver::class, new class($window) implements EnrollmentPeriodResolver
        {
            public function __construct(private EnrollmentWindow $window) {}

            public function forStudent(Student $student): ?EnrollmentWindow
            {
                return $this->window;
            }
        });

        return $window;
    }

    public function test_conversion_handoff_reuses_identity_without_creating_enrollment_or_fake_periods(): void
    {
        Sanctum::actingAs($this->case['user']);
        $this->getJson('/api/enrollment/status')->assertForbidden();
        $student = $this->converted();
        $counts = [];
        foreach (['users', 'user_profiles', 'students', 'enrollments', 'admission_decisions'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->getJson('/api/enrollment/status?student_id=999')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.academic_ready', true)->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'enrollment_period_unavailable')->assertJsonPath('data.period', null)
            ->assertJsonPath('data.student.id', $student->id)->assertJsonPath('data.student.user_id', $this->case['user']->id)
            ->assertJsonPath('data.student.profile_id', $this->case['profile']->id)
            ->assertJsonPath('data.student.course.id', $this->fixture['course']->id)
            ->assertJsonPath('data.student.curriculum.id', $this->fixture['curriculum']->id);
        $this->window();
        $this->getJson('/api/enrollment/eligibility')->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.applications_enabled', false);
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    public function test_role_boundaries_and_incomplete_academic_records(): void
    {
        $this->getJson('/api/enrollment/status')->assertUnauthorized();
        foreach ([Role::GUEST, Role::PROFESSOR, Role::ADMIN, Role::REGISTRAR_STAFF] as $role) {
            Sanctum::actingAs(User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => $role])->id]));
            $this->getJson('/api/enrollment/status')->assertForbidden();
            $this->getJson('/api/enrollment/eligibility')->assertForbidden();
        }
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        Sanctum::actingAs($user);
        $this->getJson('/api/enrollment/status')->assertOk()->assertJsonPath('data.reason', 'student_record_required')->assertJsonPath('data.eligible', false);
        foreach (['inactive', 'suspended'] as $status) {
            $user->update(['status' => $status]);
            $this->getJson('/api/enrollment/status')->assertForbidden();
        }
    }

    public function test_academic_and_conversion_checks_fail_closed(): void
    {
        $student = $this->converted();
        $this->window();
        foreach (['graduated', 'transferred', 'dropped', 'leave_of_absence'] as $status) {
            $student->update(['student_status' => $status]);
            $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'academic_review_required');
        }
        $student->update(['student_status' => 'regular']);
        $this->fixture['course']->update(['status' => 'inactive']);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'course_inactive');
        $this->fixture['course']->update(['status' => 'active']);
        $this->fixture['curriculum']->update(['status' => 'inactive']);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'curriculum_invalid');
        $this->fixture['curriculum']->update(['status' => 'active']);
        $other = Course::create(['department_id' => $this->fixture['course']->department_id, 'course_code' => 'OTHER', 'course_name' => 'Other', 'years' => 4, 'status' => 'active']);
        $this->fixture['curriculum']->update(['course_id' => $other->id]);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'curriculum_invalid');
        $this->fixture['curriculum']->update(['course_id' => $student->course_id]);
        $student->update(['student_number' => null]);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'academic_identity_incomplete');
        $student->update(['student_number' => '26-ENROLL']);
        $this->case['applicant']->fresh()->forceFill(['status' => AdmissionApplicant::ACCEPTED, 'converted_student_id' => null, 'converted_at' => null])->save();
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'admission_conversion_incomplete');
    }

    public function test_valid_legacy_student_needs_no_admission_application(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('role_name', Role::STUDENT)->value('id')]);
        $profile = $user->profile()->create(['first_name' => 'Legacy', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $this->fixture['course']->id,
            'curriculum_id' => $this->fixture['curriculum']->id, 'student_number' => 'LEGACY-1', 'year_level' => 2, 'admission_date' => now()->subYear()->toDateString(), 'student_status' => 'irregular']);
        $this->window();
        Sanctum::actingAs($user);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.eligible', true);
        $this->assertSame(0, $user->admissionApplications()->count());
    }

    public function test_term_window_and_existing_unique_key_prevent_duplicate_enrollment(): void
    {
        $student = $this->converted();
        $this->window(CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDays(2));
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.reason', 'enrollment_period_closed');
        $window = $this->window();
        $section = Section::create(['course_id' => $student->course_id, 'academic_year_id' => $window->academicYearId, 'semester_id' => $window->semesterId, 'section_name' => 'TEST-1', 'year_level' => 1, 'capacity' => 40, 'status' => 'open']);
        $fields = ['student_id' => $student->id, 'section_id' => $section->id, 'academic_year_id' => $window->academicYearId, 'semester_id' => $window->semesterId, 'enrollment_date' => now()->toDateString(), 'status' => 'cancelled'];
        Enrollment::create($fields);
        $this->getJson('/api/enrollment/status')->assertJsonPath('data.eligible', false)->assertJsonPath('data.reason', 'term_enrollment_exists')->assertJsonCount(1, 'data.enrollments');
        $this->expectException(UniqueConstraintViolationException::class);
        Enrollment::create($fields);
    }
}

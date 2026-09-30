<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\User;
use Database\Seeders\LargeDatasetCleanupSeeder;
use Database\Seeders\LargeDatasetSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class LargeDatasetCleanupEnrollmentTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('cleanup');
        // Use a continuing identity; Admission rows remain unrelated to cleanup.
        $user = User::factory()->create(['role_id' => $this->fixture['user']->role_id]);
        $profile = $user->profile()->create(['first_name' => 'Fixture', 'last_name' => 'Student', 'gender' => 'Prefer not to say']);
        $student = $this->fixture['student']->replicate();
        $student->fill(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'student_number' => 'CLEANUP-1'])->save();
        $application = $this->fixture['application']->replicate();
        $application->student_id = $student->id;
        $application->save();
        $this->fixture['target'] = $student;
        $this->fixture['target_application'] = $application;
        foreach (['users' => $user->id, 'user_profiles' => $profile->id, 'students' => $student->id] as $table => $id) {
            $this->track($table, $id);
        }
    }

    private function track(string $table, int $id): void
    {
        DB::table('generated_data_records')->insert(['dataset_key' => LargeDatasetSeeder::DATASET_KEY,
            'record_type' => $table === 'users' ? 'user' : $table, 'record_id' => $id,
            'record_created_at' => DB::table($table)->where('id', $id)->value('created_at'),
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_owned_application_and_deep_children_are_deleted_before_identity(): void
    {
        $a = $this->fixture['target_application'];
        $this->track('enrollment_applications', $a->id);
        $a->subjects()->attach($this->fixture['subjects'][0]->id);
        $id = DB::table('enrollment_application_subjects')->where('application_id', $a->id)->value('id');
        $this->track('enrollment_application_subjects', $id);
        $this->seed(LargeDatasetCleanupSeeder::class);
        $this->assertDatabaseMissing('students', ['id' => $a->student_id]);
        $this->assertDatabaseMissing('enrollment_applications', ['id' => $a->id]);
        $this->assertDatabaseHas('students', ['id' => $this->fixture['student']->id]);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $this->fixture['application']->id]);
        $this->seed(LargeDatasetCleanupSeeder::class);
        $this->assertDatabaseCount('generated_data_records', 0);
    }

    public function test_owned_final_enrollment_is_deleted_in_fk_order(): void
    {
        $a = $this->fixture['target_application'];
        $e = Enrollment::create(['student_id' => $a->student_id, 'section_id' => $this->fixture['section']->id,
            'academic_year_id' => $a->academic_year_id, 'semester_id' => $a->semester_id,
            'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
        $a->update(['enrollment_id' => $e->id, 'status' => 'enrolled']);
        $this->track('enrollment_applications', $a->id);
        $this->track('enrollments', $e->id);
        $subject = EnrollmentSubject::create(['enrollment_id' => $e->id,
            'subject_id' => $this->fixture['subjects'][0]->id, 'professor_id' => $this->fixture['professor']->id,
            'subject_status' => 'enrolled', 'remarks' => 'In Progress']);
        $this->track('enrollment_subjects', $subject->id);
        $this->seed(LargeDatasetCleanupSeeder::class);
        $this->assertDatabaseMissing('enrollments', ['id' => $e->id]);
        $this->assertDatabaseMissing('students', ['id' => $a->student_id]);
    }

    public function test_later_application_protects_student_profile_user_and_ledger(): void
    {
        $a = $this->fixture['target_application'];
        $before = $this->fixture['target']->fresh()->toArray();
        $this->seed(LargeDatasetCleanupSeeder::class);
        $this->seed(LargeDatasetCleanupSeeder::class);
        $this->assertSame($before, $this->fixture['target']->fresh()->toArray());
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a->id]);
        $this->assertDatabaseHas('users', ['id' => $this->fixture['target']->user_id]);
        $this->assertDatabaseCount('generated_data_records', 3);
    }

    public function test_unowned_staff_workflow_event_protects_even_an_owned_application(): void
    {
        $a = $this->fixture['target_application'];
        $this->track('enrollment_applications', $a->id);
        $id = DB::table('enrollment_workflow_events')->insertGetId([
            'actor_user_id' => $this->fixture['registrar']->id, 'actor_role' => 'Registrar Staff',
            'event_uuid' => (string) Str::uuid(), 'action' => 'enrollment.section_assigned',
            'subject_type' => 'application', 'subject_id' => $a->id, 'metadata' => '{}', 'created_at' => now()]);
        $this->seed(LargeDatasetCleanupSeeder::class);
        $this->assertDatabaseHas('students', ['id' => $a->student_id]);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $a->id]);
        $this->assertDatabaseHas('enrollment_workflow_events', ['id' => $id]);
    }

    public function test_legacy_command_dry_run_protects_later_activity_and_is_repeatable(): void
    {
        $target = $this->fixture['target'];
        $unrelated = $this->fixture['student'];

        $this->assertSame(0, Artisan::call('portal:legacy-large-dataset-cleanup', ['--dry-run' => true]));
        $this->assertStringContainsString('protected Students retained', Artisan::output());
        $this->assertDatabaseHas('students', ['id' => $target->id]);

        $this->assertSame(0, Artisan::call('portal:legacy-large-dataset-cleanup', ['--execute' => true]));
        $this->assertDatabaseHas('students', ['id' => $target->id]);
        $this->assertDatabaseHas('students', ['id' => $unrelated->id]);
        $this->assertDatabaseHas('enrollment_applications', ['id' => $this->fixture['target_application']->id]);
        $this->assertSame(0, Artisan::call('portal:legacy-large-dataset-cleanup', ['--execute' => true]));
        $this->assertDatabaseHas('students', ['id' => $target->id]);
    }
}

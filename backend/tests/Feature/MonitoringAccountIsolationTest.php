<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\MonitoringPerformanceRecord;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringAccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_cannot_read_or_overwrite_another_students_monitoring_files(): void
    {
        $owner = $this->createStudent('26-00011', 'Owner');
        $other = $this->createStudent('26-00012', 'Other');
        $privateText = 'Owner private notes about nested loops and arrays for this account only.';

        Sanctum::actingAs($owner->user);
        $upload = $this->post("/api/monitoring/students/{$owner->id}/performance-records", [
            'subject_code' => 'IT101',
            'subject_name' => 'Introduction to Computing',
            'assessment_name' => 'Uploaded file',
            'topic' => 'Owner notes',
            'attachment' => UploadedFile::fake()->createWithContent('owner-notes.txt', $privateText),
        ]);
        $upload->assertCreated();
        $recordId = (int) $upload->json('data.id');

        Sanctum::actingAs($other->user);

        $this->getJson("/api/monitoring/students/{$owner->id}/performance-records")->assertForbidden();
        $this->getJson("/api/monitoring/performance-records/{$recordId}/attachment")->assertForbidden();
        $this->postJson('/api/monitoring/study-studio/flashcards', ['record_id' => $recordId])->assertStatus(422);
        $this->post("/api/monitoring/students/{$owner->id}/performance-records", [
            'subject_code' => 'IT101',
            'assessment_name' => 'Uploaded file',
            'topic' => 'Should not save',
            'attachment' => UploadedFile::fake()->createWithContent('other-notes.txt', 'Other student attempt to overwrite the first account.'),
        ])->assertForbidden();

        $this->assertSame(1, MonitoringPerformanceRecord::query()->count());
        $this->assertSame($owner->id, MonitoringPerformanceRecord::query()->value('student_id'));

        $studio = $this->getJson('/api/monitoring/study-studio')->assertOk()->json('data');
        $this->assertSame($other->id, $studio['student_id']);
        $this->assertSame([], $studio['records']);

        $risk = $this->getJson('/api/monitoring/my-risk')->assertOk()->json('data.students');
        $this->assertCount(1, $risk);
        $this->assertSame($other->id, $risk[0]['student_id']);
        $this->assertSame('26-00012', $risk[0]['student_number']);
    }

    public function test_a_student_study_guide_is_written_from_the_uploaded_syllabus(): void
    {
        $student = $this->createStudent('26-00021', 'Mia');
        $syllabus = 'Week 1 variables and data types. Week 2 conditions and loops. Week 3 functions and arrays. Course outcome: write a small program.';

        Sanctum::actingAs($student->user);
        $upload = $this->post("/api/monitoring/students/{$student->id}/performance-records", [
            'subject_code' => 'SYLLABUS',
            'subject_name' => 'Course syllabus',
            'assessment_name' => 'Syllabus',
            'topic' => 'IT101 syllabus',
            'attachment' => UploadedFile::fake()->createWithContent('it101-syllabus.txt', $syllabus),
        ]);
        $upload->assertCreated();
        $recordId = (int) $upload->json('data.id');

        $subjects = $this->getJson('/api/monitoring/my-risk')->assertOk()->json('data.students.0.subjects');
        $this->assertSame([], $subjects);

        Config::set('ai.provider', 'gemini');
        Config::set('ai.api_key', 'test-key');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['reply' => 'Study variables in week 1, then conditions, loops, functions, and arrays.']),
                    ]]],
                ]],
            ]),
        ]);

        $this->postJson('/api/monitoring/study-studio/plan', ['record_id' => $recordId])
            ->assertOk()
            ->assertJsonPath('data.title', 'Study guide · it101-syllabus.txt')
            ->assertJsonPath('data.plan', 'Study variables in week 1, then conditions, loops, functions, and arrays.');

        Http::assertSent(function ($request) use ($syllabus) {
            $body = $request->body();

            return str_contains($body, 'course syllabus') && str_contains($body, $syllabus);
        });
    }

    private function createStudent(string $number, string $firstName): Student
    {
        DB::table('departments')->insertOrIgnore([
            'id' => 1,
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = Role::firstOrCreate(['role_name' => Role::STUDENT], ['description' => Role::STUDENT]);
        $user = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
        $profile = UserProfile::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => 'Student',
            'gender' => 'Prefer not to say',
            'nationality' => 'Filipino',
            'email' => "{$number}@example.test",
        ]);
        $course = Course::firstOrCreate(
            ['course_code' => 'BSIT'],
            ['department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active'],
        );
        $curriculum = Curriculum::firstOrCreate(
            ['curriculum_code' => 'BSIT-2026'],
            ['course_id' => $course->id, 'curriculum_name' => 'BSIT Curriculum', 'effective_year' => 2026, 'status' => 'active'],
        );

        return Student::create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'course_id' => $course->id,
            'curriculum_id' => $curriculum->id,
            'student_number' => $number,
            'admission_date' => '2026-08-01',
            'year_level' => 1,
            'student_status' => 'regular',
        ]);
    }
}

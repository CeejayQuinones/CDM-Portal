<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentRosterImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = 'student_number,first_name,middle_name,last_name,suffix,enrollment_email,course_code,curriculum_code,year_level,student_status,admission_date,gender,birth_date,contact_number,address,record_reference';

    public function test_preview_does_not_save_and_confirmation_creates_unclaimed_student_and_alumni_records(): void
    {
        $this->catalog();
        $registrar = $this->registrar();
        $token = $registrar->createToken('desktop', ['client:desktop'])->plainTextToken;
        $csv = self::HEADERS."\n".
            '26-70001,Ana,,Santos,,ana@example.test,BSIT,BSIT-2026,2,regular,2025-06-01,Female,2005-01-01,,,File A'."\n".
            '19-00002,Jose,,Cruz,,,BSIT,BSIT-2026,4,graduated,2019-06-01,Male,,,,Archive B'."\n";
        $response = $this->withToken($token)->post('/api/students/roster-import/preview', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.counts.valid', 2);
        $this->assertSame(0, Student::query()->count());
        $this->assertStringNotContainsString('ana@example.test', DB::table('student_roster_import_previews')->value('rows'));
        $previewToken = $response->json('data.preview_token');
        $this->withToken($token)->postJson('/api/students/roster-import/confirm', ['preview_token' => $previewToken])->assertStatus(428);
        $this->withToken($token)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->withToken($token)->postJson('/api/students/roster-import/confirm', ['preview_token' => $previewToken])->assertOk()->assertJsonPath('data.imported', 2);
        $this->assertDatabaseHas('students', ['student_number' => '19-00002', 'student_status' => 'graduated', 'year_level' => 4]);
        $student = Student::query()->where('student_number', '26-70001')->firstOrFail();
        $this->assertSame('inactive', $student->user->status);
        $this->assertTrue($student->user->is_first_login);
        $this->assertSame('ana@example.test', $student->userProfile->email);
        $this->assertDatabaseMissing('student_account_activations', ['student_id' => $student->id]);
        $this->assertDatabaseHas('student_record_audit_events', ['student_id' => $student->id, 'action' => 'student_roster_record_imported']);
        $this->withToken($token)->postJson('/api/students/roster-import/confirm', ['preview_token' => $previewToken])->assertStatus(409);
    }

    public function test_duplicate_or_invalid_row_blocks_entire_batch(): void
    {
        $this->catalog();
        $token = $this->registrar()->createToken('desktop', ['client:desktop'])->plainTextToken;
        $csv = self::HEADERS."\n".
            '26-70001,Ana,,Santos,,ana@example.test,BSIT,BSIT-2026,2,regular,2025-06-01,Female,2005-01-01,,,File A'."\n".
            '26-70001,Ben,,Santos,,ben@example.test,BSIT,BSIT-2026,9,regular,2025-06-01,Male,,,,File B'."\n";
        $this->withToken($token)->post('/api/students/roster-import/preview', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.counts.invalid', 1)->assertJsonPath('data.preview_token', null);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_admin_cannot_import(): void
    {
        $this->catalog();
        $admin = User::factory()->create(['role_id' => Role::query()->firstOrCreate(['role_name' => Role::ADMIN])->id]);
        $adminToken = $admin->createToken('desktop', ['client:desktop'])->plainTextToken;
        $this->withToken($adminToken)->get('/api/students/roster-import/template')->assertForbidden();
        $this->withToken($adminToken)->post('/api/students/roster-import/preview', ['file' => $this->csv(self::HEADERS."\n")], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_missing_file_is_rejected(): void
    {
        $registrarToken = $this->registrar()->createToken('desktop', ['client:desktop'])->plainTextToken;
        $this->withToken($registrarToken)->postJson('/api/students/roster-import/preview', [])->assertUnprocessable();
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('roster.csv', $body);
    }

    private function registrar(): User
    {
        return User::factory()->create(['role_id' => Role::query()->firstOrCreate(['role_name' => Role::REGISTRAR_STAFF])->id]);
    }

    private function catalog(): void
    {
        Role::query()->firstOrCreate(['role_name' => Role::STUDENT]);
        DB::table('departments')->insert(['department_code' => 'IT', 'department_name' => 'IT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = DB::table('courses')->insertGetId(['department_id' => DB::table('departments')->value('id'), 'course_code' => 'BSIT', 'course_name' => 'BSIT', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curriculums')->insert(['course_id' => $course, 'curriculum_code' => 'BSIT-2026', 'curriculum_name' => 'BSIT 2026', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
}

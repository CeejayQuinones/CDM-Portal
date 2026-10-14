<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRecordAuditEvent;
use App\Models\StudentRecordLocation;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StudentRecordReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_and_admin_can_access_paginated_filtered_reports(): void
    {
        $student = $this->student('26-10001', 'Ana', 'Reyes');
        $this->enroll($student, '2026-2027', 'First Semester', 'BSIT-1A');

        foreach ([Role::REGISTRAR_STAFF, Role::ADMIN] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/student-record-reports/options')
                ->assertOk()
                ->assertJsonPath('data.courses.0.course_code', 'BSIT');
            $this->getJson('/api/student-record-reports/master-list?academic_year_id=1&semester_id=1&search=Ana&per_page=10')
                ->assertOk()
                ->assertJsonPath('data.rows.0.student_number', '26-10001')
                ->assertJsonPath('data.rows.0.section', 'BSIT-1A')
                ->assertJsonPath('data.summary.total_students', 1)
                ->assertJsonPath('data.pagination.per_page', 10);
        }
    }

    public function test_non_staff_and_inactive_staff_are_denied(): void
    {
        foreach ([Role::GUEST, Role::STUDENT, Role::PROFESSOR] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/student-record-reports/master-list')->assertForbidden();
            $this->get('/api/student-record-reports/master-list/export/excel')->assertForbidden();
            $this->get('/api/student-record-reports/master-list/export/pdf')->assertForbidden();
        }

        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF, 'inactive'));
        $this->getJson('/api/student-record-reports/master-list')->assertForbidden();
    }

    public function test_historical_term_and_physical_status_use_authoritative_records(): void
    {
        $student = $this->student('26-10002', 'Ben', 'Santos');
        $this->enroll($student, '2025-2026', 'First Semester', 'BSIT-1A');
        $this->enroll($student, '2026-2027', 'Second Semester', 'BSIT-2B');
        $cabinet = Cabinet::query()->create(['cabinet_code' => 'A', 'rows' => 1, 'columns' => 1]);
        $slot = $cabinet->slots()->create(['slot_code' => 'A1', 'capacity' => 20]);
        StudentRecordLocation::query()->create(['student_id' => $student->id, 'cabinet_slot_id' => $slot->id, 'assigned_at' => now()]);
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        StudentRecordAuditEvent::query()->create([
            'student_id' => $student->id,
            'actor_user_id' => $registrar->id,
            'action' => 'physical_record_location_changed',
            'metadata' => ['new_cabinet_slot_id' => $slot->id],
            'created_at' => now(),
        ]);
        Sanctum::actingAs($registrar);

        $this->getJson('/api/student-record-reports/master-list?academic_year_id=1&semester_id=1')
            ->assertOk()->assertJsonPath('data.rows.0.academic_year', '2025-2026')->assertJsonPath('data.rows.0.section', 'BSIT-1A');
        $this->getJson('/api/student-record-reports/physical-records?availability=available&cabinet_id='.$cabinet->id)
            ->assertOk()->assertJsonPath('data.rows.0.physical_record_status', 'Available')->assertJsonPath('data.rows.0.location', 'A1');
        $filters = http_build_query([
            'student_id' => $student->id,
            'staff_user_id' => $registrar->id,
            'action_type' => 'physical_record_location_changed',
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ]);
        $this->getJson('/api/student-record-reports/activity-summary?'.$filters)
            ->assertOk()->assertJsonPath('data.rows.0.action_type', 'physical_record_location_changed');
        $csv = $this->get('/api/student-record-reports/activity-summary/export?'.$filters)->assertOk()->streamedContent();
        $this->assertStringContainsString('physical_record_location_changed', $csv);
        $this->assertStringNotContainsString('new_cabinet_slot_id', $csv);
        $this->assertStringNotContainsString('metadata', strtolower($csv));
    }

    public function test_academic_report_exposes_only_published_counts_and_csv_respects_filters(): void
    {
        $student = $this->student('26-10003', 'Cara', 'Mendoza');
        $this->enroll($student, '2026-2027', 'First Semester', 'BSIT-1A');
        $this->student('26-10004', 'Different', 'Program');
        Sanctum::actingAs($this->user(Role::ADMIN));

        $response = $this->getJson('/api/student-record-reports/academic-summary?search=Cara');
        $response->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.rows.0.published_subject_count', 0);
        $this->assertArrayNotHasKey('final_grade', $response->json('data.rows.0'));
        $this->assertArrayNotHasKey('email', $response->json('data.rows.0'));

        $csv = $this->get('/api/student-record-reports/academic-summary/export?search=Cara')->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('26-10003', $content);
        $this->assertStringNotContainsString('26-10004', $content);
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('email', strtolower($content));
    }

    public function test_empty_and_large_result_sets_remain_valid_and_paginated(): void
    {
        foreach (range(1, 12) as $index) {
            $this->student(sprintf('26-2%04d', $index), 'Student'.$index, 'Example');
        }
        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF));

        $this->getJson('/api/student-record-reports/master-list?per_page=10')
            ->assertOk()->assertJsonCount(10, 'data.rows')->assertJsonPath('data.pagination.total', 12)->assertJsonPath('data.pagination.last_page', 2);
        $this->getJson('/api/student-record-reports/master-list?search=Nobody')
            ->assertOk()->assertJsonPath('data.rows', [])->assertJsonPath('data.pagination.total', 0);
    }

    public function test_excel_and_pdf_exports_use_the_same_filters_and_safe_download_headers(): void
    {
        $this->student('26-30001', 'Exported', 'Student');
        $this->student('26-30002', 'Excluded', 'Student');
        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF));

        $excel = $this->get('/api/student-record-reports/master-list/export/excel?search=Exported')
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $excel->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('Student Number', $sheet->getCell('A1')->getValue());
        $this->assertSame('26-30001', $sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());

        $pdf = $this->get('/api/student-record-reports/master-list/export/pdf?search=Exported')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('.pdf', $pdf->headers->get('content-disposition'));
    }

    private function student(string $number, string $first, string $last): Student
    {
        DB::table('departments')->insertOrIgnore(['id' => 1, 'department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->user(Role::STUDENT);
        $profile = UserProfile::query()->create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'email' => strtolower($first).'.student@example.test']);
        $course = Course::query()->firstOrCreate(['course_code' => 'BSIT'], ['department_id' => 1, 'course_name' => 'Information Technology', 'years' => 4, 'status' => 'active']);
        $curriculum = Curriculum::query()->firstOrCreate(['curriculum_code' => 'BSIT-2026'], ['course_id' => $course->id, 'curriculum_name' => 'BSIT 2026', 'effective_year' => 2026, 'status' => 'active']);

        return Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile->id, 'course_id' => $course->id, 'curriculum_id' => $curriculum->id, 'student_number' => $number, 'admission_date' => '2026-08-01', 'year_level' => 1, 'student_status' => 'regular']);
    }

    private function enroll(Student $student, string $year, string $semester, string $section): void
    {
        $yearId = DB::table('academic_years')->insertGetId(['school_year' => $year, 'start_date' => str_starts_with($year, '2025') ? '2025-08-01' : '2026-08-01', 'end_date' => str_starts_with($year, '2025') ? '2026-05-31' : '2027-05-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $semesterId = DB::table('semesters')->insertGetId(['semester_name' => $semester.' '.$year, 'semester_order' => str_contains($semester, 'Second') ? 2 : 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $sectionId = DB::table('sections')->insertGetId(['course_id' => $student->course_id, 'academic_year_id' => $yearId, 'semester_id' => $semesterId, 'section_name' => $section, 'year_level' => str_contains($section, '-2') ? 2 : 1, 'capacity' => 40, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $student->id, 'section_id' => $sectionId, 'academic_year_id' => $yearId, 'semester_id' => $semesterId, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $role, string $status = 'active'): User
    {
        $model = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::factory()->create(['role_id' => $model->id, 'status' => $status]);
    }
}

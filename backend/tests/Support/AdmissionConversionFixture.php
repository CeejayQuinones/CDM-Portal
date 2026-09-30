<?php

namespace Tests\Support;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

class AdmissionConversionFixture
{
    public static function create(string $nonce): array
    {
        foreach ([Role::GUEST, Role::STUDENT, Role::REGISTRAR_STAFF] as $role) {
            Role::firstOrCreate(['role_name' => $role]);
        }
        $registrar = User::factory()->create(['username' => 'cv-'.$nonce.'-registrar', 'role_id' => Role::where('role_name', Role::REGISTRAR_STAFF)->value('id')]);
        $year = AcademicYear::create(['school_year' => 'CV-'.$nonce, 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear(), 'status' => 'active']);
        $department = Department::create(['department_code' => 'C'.$nonce, 'department_name' => 'Conversion test only', 'status' => 'active']);
        $course = Course::create(['department_id' => $department->id, 'course_code' => 'CV-'.$nonce, 'course_name' => 'Conversion test only', 'years' => 4, 'status' => 'active']);
        $curriculum = Curriculum::create(['course_id' => $course->id, 'curriculum_code' => 'CV-'.$nonce, 'curriculum_name' => 'Conversion test only', 'effective_year' => now()->year, 'status' => 'active']);
        $cycle = AdmissionCycle::create(['code' => 'CV-'.$nonce, 'name' => 'Conversion test only', 'academic_year_id' => $year->id, 'status' => 'open', 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'confirmation_closes_at' => now()->addDays(2), 'created_by_user_id' => $registrar->id, 'updated_by_user_id' => $registrar->id]);
        $cases = [];
        foreach ([1, 2, 3] as $index) {
            $user = User::factory()->create(['username' => 'cv-'.$nonce.'-'.$index, 'role_id' => Role::where('role_name', Role::GUEST)->value('id')]);
            $profile = $user->profile()->create(['first_name' => 'Conversion', 'last_name' => 'Test '.$index, 'gender' => 'Prefer not to say']);
            $applicant = new AdmissionApplicant;
            $applicant->forceFill(['user_id' => $user->id, 'cycle_id' => $cycle->id])->save();
            $session = new AdmissionExamSession;
            $session->forceFill(['id' => (string) Str::uuid(), 'applicant_id' => $applicant->id, 'attempt_number' => 1, 'status' => 'finalized', 'bank_version' => str_repeat('a', 64), 'policy_snapshot' => [], 'started_at' => now()->subHour(), 'deadline_at' => now()->addHour(), 'finalized_at' => now()])->save();
            $result = AdmissionExamResult::create(['session_id' => $session->id, 'raw_correct_count' => 80, 'question_count' => 100, 'system_percentage' => 80, 'system_passed' => true, 'category_scores' => [], 'category_maximums' => [], 'time_spent_seconds' => 3600, 'finalized_at' => now(), 'finalization_cause' => 'submit', 'official_score' => 80, 'official_status' => 'published', 'published_at' => now(), 'version' => 1]);
            $cases[] = compact('user', 'profile', 'applicant', 'session', 'result');
        }

        return compact('registrar', 'year', 'department', 'course', 'curriculum', 'cycle', 'cases');
    }
}

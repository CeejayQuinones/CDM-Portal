<?php

namespace Tests\Support;

use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admission\AdmissionConversionService;
use App\Services\Enrollment\EnrollmentWorkflowService;

class EnrollmentAcademicFixture
{
    public static function create(string $nonce): array
    {
        $f = AdmissionConversionFixture::create($nonce);
        $case = $f['cases'][0];
        $service = app(AdmissionConversionService::class);
        $a = $case['applicant'];
        $input = ['version' => $a->version, 'result_id' => $case['result']->id, 'result_version' => $case['result']->version, 'course_id' => $f['course']->id, 'curriculum_id' => $f['curriculum']->id, 'confirmed' => true];
        $service->accept($f['registrar'], $a->id, $input);
        $input['version'] = $a->fresh()->version;
        $service->convert($f['registrar'], $a->id, $input + ['student_number' => 'AC-'.$nonce, 'admission_date' => now()->toDateString()]);
        $user = $case['user']->fresh();
        $student = $user->student;
        $semester = Semester::create(['semester_name' => 'Academic '.$nonce, 'semester_order' => 1, 'status' => 'active']);
        $period = EnrollmentPeriod::create(['academic_year_id' => $f['year']->id, 'semester_id' => $semester->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'enabled' => true, 'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []], 'created_by' => $f['registrar']->id, 'updated_by' => $f['registrar']->id]);
        $workflow = app(EnrollmentWorkflowService::class);
        $application = $workflow->create($user, ['period_id' => $period->id, 'classification' => 'regular']);
        foreach (['submit', 'review', 'approve'] as $action) {
            $application = $workflow->mutate($action === 'submit' ? $user : $f['registrar'], $application->id, $action, ['version' => $application->version, 'notes' => 'Test review.']);
        }
        $subjects = [];
        foreach ([1, 2, 3] as $i) {
            $subjects[] = Subject::create(['curriculum_id' => $f['curriculum']->id, 'semester_id' => $semester->id, 'subject_code' => 'AC'.$i, 'subject_name' => 'Academic subject '.$i, 'year_level' => $i === 3 ? 2 : 1, 'units' => 3, 'lecture_hours' => 3, 'laboratory_hours' => 0, 'status' => 'active']);
        }
        $professorUser = User::factory()->create(['role_id' => Role::firstOrCreate(['role_name' => Role::PROFESSOR])->id]);
        $profile = $professorUser->profile()->create(['first_name' => 'Academic', 'last_name' => 'Professor', 'gender' => 'Prefer not to say']);
        $professor = Professor::create(['user_id' => $professorUser->id, 'user_profile_id' => $profile->id, 'department_id' => $f['department']->id, 'employee_number' => 'AP-'.$nonce, 'status' => 'active']);
        $section = Section::create(['course_id' => $f['course']->id, 'academic_year_id' => $f['year']->id, 'semester_id' => $semester->id, 'section_name' => 'AC-'.$nonce, 'year_level' => 1, 'capacity' => 2, 'status' => 'open']);

        return $f + compact('user', 'student', 'period', 'application', 'subjects', 'professorUser', 'professor', 'section', 'semester');
    }
}

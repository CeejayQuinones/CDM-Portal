<?php
/**
 * Paste-friendly Railway Console seed (no artisan command required).
 * Usage inside /app:
 *   php /tmp/seed_demo_inline.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

DB::transaction(function () {
    $roles = [
        1 => 'Guest',
        2 => 'Admin',
        3 => 'Registrar Staff',
        4 => 'Professor',
        5 => 'Student',
    ];
    foreach ($roles as $id => $name) {
        DB::table('roles')->updateOrInsert(
            ['id' => $id],
            ['role_name' => $name, 'description' => $name, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    $roleIds = DB::table('roles')->pluck('id', 'role_name');
    $accounts = [
        'ken' => ['ken123!', 'Guest', 'Kenneth', null, 'Gallaza', 'ken@example.com'],
        'admin' => ['Admin123!', 'Admin', 'System', null, 'Administrator', 'admin@cdm.edu.ph'],
        'registrar1' => ['Registrar123!', 'Registrar Staff', 'Maria', 'Santos', 'Cruz', 'registrar@cdm.edu.ph'],
        'professor1' => ['Professor123!', 'Professor', 'Juan', 'Dela', 'Reyes', 'professor@cdm.edu.ph'],
        'student1' => ['Student123!', 'Student', 'John', 'A.', 'Doe', 'student@cdm.edu.ph'],
    ];

    $users = [];
    $profiles = [];
    foreach ($accounts as $username => [$password, $role, $first, $middle, $last, $email]) {
        $existing = DB::table('users')->where('username', $username)->first();
        $payload = [
            'password' => Hash::make($password),
            'role_id' => $roleIds[$role],
            'status' => 'active',
            'is_first_login' => false,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('users')->insert($payload + ['username' => $username, 'last_login' => null, 'created_at' => now()]);
        }
        $users[$username] = DB::table('users')->where('username', $username)->first();

        $p = DB::table('user_profiles')->where('user_id', $users[$username]->id)->first();
        $pp = [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'email' => $email,
            'gender' => 'Prefer not to say',
            'civil_status' => 'Single',
            'contact_number' => '09170000000',
            'address' => 'Rodriguez, Rizal',
            'nationality' => 'Filipino',
            'updated_at' => now(),
        ];
        if ($p) {
            DB::table('user_profiles')->where('id', $p->id)->update($pp);
        } else {
            DB::table('user_profiles')->insert($pp + ['user_id' => $users[$username]->id, 'created_at' => now()]);
        }
        $profiles[$username] = DB::table('user_profiles')->where('user_id', $users[$username]->id)->first();
        echo "user {$username}\n";
    }

    DB::table('departments')->updateOrInsert(
        ['department_code' => 'ICS'],
        ['department_name' => 'Institute of Computer Studies', 'description' => 'ICS', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
    );
    $deptId = (int) DB::table('departments')->where('department_code', 'ICS')->value('id');

    DB::table('courses')->updateOrInsert(
        ['course_code' => 'BSIT'],
        ['department_id' => $deptId, 'course_name' => 'BS Information Technology', 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
    );
    $courseId = (int) DB::table('courses')->where('course_code', 'BSIT')->value('id');

    DB::table('curriculums')->updateOrInsert(
        ['curriculum_code' => 'BSIT-2026'],
        ['course_id' => $courseId, 'curriculum_name' => 'BSIT Curriculum 2026', 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
    );
    $curriculumId = (int) DB::table('curriculums')->where('curriculum_code', 'BSIT-2026')->value('id');

    DB::table('academic_years')->updateOrInsert(
        ['school_year' => '2026-2027'],
        ['start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
    );
    $ayId = (int) DB::table('academic_years')->where('school_year', '2026-2027')->value('id');

    foreach ([['First Semester', 1], ['Second Semester', 2]] as [$n, $o]) {
        DB::table('semesters')->updateOrInsert(
            ['semester_name' => $n],
            ['semester_order' => $o, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );
    }
    $semId = (int) DB::table('semesters')->where('semester_name', 'First Semester')->value('id');

    foreach ([['Prelim', 1], ['Midterm', 2], ['Final', 3]] as [$n, $o]) {
        DB::table('grading_periods')->updateOrInsert(
            ['period_name' => $n],
            ['period_order' => $o, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );
    }
    $periods = DB::table('grading_periods')->pluck('id', 'period_name');

    foreach ([['IT101', 'Introduction to Computing'], ['IT102', 'Computer Programming 1'], ['IT103', 'Discrete Mathematics']] as [$code, $name]) {
        DB::table('subjects')->updateOrInsert(
            ['subject_code' => $code, 'curriculum_id' => $curriculumId],
            [
                'semester_id' => $semId,
                'subject_name' => $name,
                'year_level' => 1,
                'units' => 3,
                'lecture_hours' => 2,
                'laboratory_hours' => 3,
                'prerequisite_subject_id' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    if (Schema::hasTable('registrar_staff')) {
        DB::table('registrar_staff')->updateOrInsert(
            ['employee_number' => 'REG-2026-001'],
            [
                'user_id' => $users['registrar1']->id,
                'user_profile_id' => $profiles['registrar1']->id,
                'position' => 'Registrar Staff',
                'employment_status' => 'regular',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    DB::table('professors')->updateOrInsert(
        ['employee_number' => 'FAC-2026-001'],
        [
            'user_id' => $users['professor1']->id,
            'user_profile_id' => $profiles['professor1']->id,
            'department_id' => $deptId,
            'position' => 'Instructor I',
            'specialization' => 'Software Development',
            'employment_status' => 'full_time',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
    $professorId = (int) DB::table('professors')->where('employee_number', 'FAC-2026-001')->value('id');

    DB::table('students')->updateOrInsert(
        ['student_number' => '26-00001'],
        [
            'user_id' => $users['student1']->id,
            'user_profile_id' => $profiles['student1']->id,
            'course_id' => $courseId,
            'curriculum_id' => $curriculumId,
            'admission_date' => '2026-08-01',
            'year_level' => 1,
            'student_status' => 'regular',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
    $studentId = (int) DB::table('students')->where('student_number', '26-00001')->value('id');

    DB::table('sections')->updateOrInsert(
        ['course_id' => $courseId, 'academic_year_id' => $ayId, 'semester_id' => $semId, 'section_name' => 'BSIT-1A'],
        ['year_level' => 1, 'adviser_id' => $professorId, 'capacity' => 40, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]
    );
    $sectionId = (int) DB::table('sections')->where('section_name', 'BSIT-1A')->where('academic_year_id', $ayId)->value('id');

    DB::table('enrollments')->updateOrInsert(
        ['student_id' => $studentId, 'academic_year_id' => $ayId, 'semester_id' => $semId],
        ['section_id' => $sectionId, 'enrollment_date' => '2026-08-15', 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]
    );
    $enrollmentId = (int) DB::table('enrollments')->where('student_id', $studentId)->where('academic_year_id', $ayId)->value('id');

    $gradeMap = [
        'IT101' => [78, 74, 72],
        'IT102' => [68, 65, 70],
        'IT103' => [82, 79, 80],
    ];
    $subjects = DB::table('subjects')->whereIn('subject_code', array_keys($gradeMap))->get()->keyBy('subject_code');

    foreach ($subjects as $code => $subject) {
        DB::table('enrollment_subjects')->updateOrInsert(
            ['enrollment_id' => $enrollmentId, 'subject_id' => $subject->id],
            ['professor_id' => $professorId, 'subject_status' => 'enrolled', 'remarks' => 'In Progress', 'created_at' => now(), 'updated_at' => now()]
        );
        $esId = (int) DB::table('enrollment_subjects')->where('enrollment_id', $enrollmentId)->where('subject_id', $subject->id)->value('id');
        foreach (['Prelim', 'Midterm', 'Final'] as $i => $period) {
            $g = $gradeMap[$code][$i];
            DB::table('grades')->updateOrInsert(
                ['enrollment_subject_id' => $esId, 'grading_period_id' => $periods[$period]],
                [
                    'professor_id' => $professorId,
                    'grade' => $g,
                    'remarks' => $g >= 75 ? 'Passed' : 'Failed',
                    'status' => 'approved',
                    'submitted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    if (Schema::hasTable('monitoring_performance_records')) {
        $samples = [
            ['IT102', 'Computer Programming 1', 'Quiz 2', 'Loops and nested conditionals', 8, 20, 'Struggles with loop control.'],
            ['IT102', 'Computer Programming 1', 'Lab 3', 'Arrays and indexing', 12, 25, 'Needs array practice.'],
            ['IT101', 'Introduction to Computing', 'Short Quiz', 'Number systems conversion', 9, 15, 'Binary conversion inconsistent.'],
        ];
        foreach ($samples as [$sc, $sn, $an, $topic, $score, $max, $notes]) {
            $q = [
                'student_id' => $studentId,
                'professor_user_id' => $users['professor1']->id,
                'assessment_name' => $an,
                'topic' => $topic,
            ];
            $row = [
                'subject_code' => $sc,
                'subject_name' => $sn,
                'score' => $score,
                'max_score' => $max,
                'notes' => $notes,
                'updated_at' => now(),
            ];
            if (DB::table('monitoring_performance_records')->where($q)->exists()) {
                DB::table('monitoring_performance_records')->where($q)->update($row);
            } else {
                DB::table('monitoring_performance_records')->insert($q + $row + ['created_at' => now()]);
            }
        }
    }

    if (Schema::hasTable('monitoring_sent_plans')) {
        $title = 'Study plan: Quiz 2 · Loops and nested conditionals';
        if (! DB::table('monitoring_sent_plans')->where('student_id', $studentId)->where('title', $title)->exists()) {
            DB::table('monitoring_sent_plans')->insert([
                'student_id' => $studentId,
                'sender_user_id' => $users['professor1']->id,
                'performance_record_id' => DB::table('monitoring_performance_records')->where('topic', 'Loops and nested conditionals')->value('id'),
                'title' => $title,
                'topic' => 'Loops and nested conditionals',
                'subject_code' => 'IT102',
                'plan_body' => "Day 1: Review loops.\nDay 2: Trace nested loops.\nDay 3: Fix Quiz 2 mistakes.\nDay 4: Practice drills.\nDay 5: Ask Prof. Reyes.",
                'source' => 'cdm-coach',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    echo "DONE student={$studentId} professor={$professorId}\n";
});

echo "OK\n";

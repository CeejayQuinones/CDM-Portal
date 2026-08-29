<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MonitoringDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $activeYearId = (int) DB::table('academic_years')->where('status', 'active')->value('id');
        $semesterId = (int) DB::table('semesters')->orderBy('id')->value('id');
        $courseId = (int) DB::table('courses')->where('course_code', 'BSIT')->value('id');
        $curriculumId = (int) DB::table('curriculums')->where('course_id', $courseId)->value('id');
        $professorId = (int) DB::table('professors')->orderBy('id')->value('id');
        $studentRoleId = (int) DB::table('roles')->where('role_name', 'Student')->value('id');

        $extraStudents = [
            [
                'username' => 'student2',
                'password' => Hash::make('Student123!'),
                'first_name' => 'Ana',
                'middle_name' => 'M.',
                'last_name' => 'Santos',
                'email' => 'ana.santos@cdm.edu.ph',
                'student_number' => '26-00002',
                'gender' => 'Female',
            ],
            [
                'username' => 'student3',
                'password' => Hash::make('Student123!'),
                'first_name' => 'Carlos',
                'middle_name' => 'R.',
                'last_name' => 'Reyes',
                'email' => 'carlos.reyes@cdm.edu.ph',
                'student_number' => '26-00003',
                'gender' => 'Male',
            ],
        ];

        foreach ($extraStudents as $extra) {
            if (DB::table('users')->where('username', $extra['username'])->exists()) {
                continue;
            }

            $userId = DB::table('users')->insertGetId([
                'username' => $extra['username'],
                'password' => $extra['password'],
                'role_id' => $studentRoleId,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $profileId = DB::table('user_profiles')->insertGetId([
                'user_id' => $userId,
                'first_name' => $extra['first_name'],
                'middle_name' => $extra['middle_name'],
                'last_name' => $extra['last_name'],
                'suffix' => null,
                'gender' => $extra['gender'],
                'birth_date' => '2005-06-15',
                'civil_status' => 'Single',
                'email' => $extra['email'],
                'contact_number' => '09190000000',
                'address' => 'Rodriguez, Rizal',
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('students')->insert([
                'user_id' => $userId,
                'user_profile_id' => $profileId,
                'course_id' => $courseId,
                'curriculum_id' => $curriculumId,
                'student_number' => $extra['student_number'],
                'admission_date' => '2026-08-01',
                'year_level' => 1,
                'student_status' => 'regular',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sectionId = DB::table('sections')->insertGetId([
            'course_id' => $courseId,
            'academic_year_id' => $activeYearId,
            'semester_id' => $semesterId,
            'section_name' => 'BSIT-1A',
            'year_level' => 1,
            'adviser_id' => $professorId,
            'capacity' => 40,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subjectIds = DB::table('subjects')
            ->where('curriculum_id', $curriculumId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $periodIds = DB::table('grading_periods')
            ->orderBy('period_order')
            ->pluck('id', 'period_name');

        $gradeSets = [
            '26-00001' => [
                // High risk + declining
                ['Prelim' => 82, 'Midterm' => 76],
                ['Prelim' => 74, 'Midterm' => 68],
                ['Prelim' => 88, 'Midterm' => 90],
            ],
            '26-00002' => [
                // Moderate risk
                ['Prelim' => 80, 'Midterm' => 78],
                ['Prelim' => 79, 'Midterm' => 77],
                ['Prelim' => 85, 'Midterm' => 84],
            ],
            '26-00003' => [
                // Stable / low risk
                ['Prelim' => 90, 'Midterm' => 92],
                ['Prelim' => 88, 'Midterm' => 91],
                ['Prelim' => 93, 'Midterm' => 94],
            ],
        ];

        $students = DB::table('students')
            ->whereIn('student_number', array_keys($gradeSets))
            ->get(['id', 'student_number']);

        foreach ($students as $student) {
            $enrollmentId = DB::table('enrollments')->insertGetId([
                'student_id' => $student->id,
                'section_id' => $sectionId,
                'academic_year_id' => $activeYearId,
                'semester_id' => $semesterId,
                'enrollment_date' => '2026-08-01',
                'status' => 'enrolled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($subjectIds as $index => $subjectId) {
                $enrollmentSubjectId = DB::table('enrollment_subjects')->insertGetId([
                    'enrollment_id' => $enrollmentId,
                    'subject_id' => $subjectId,
                    'professor_id' => $professorId,
                    'subject_status' => 'enrolled',
                    'final_grade' => null,
                    'remarks' => 'In Progress',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $periodGrades = $gradeSets[$student->student_number][$index] ?? ['Prelim' => 85, 'Midterm' => 85];

                foreach ($periodGrades as $periodName => $score) {
                    DB::table('grades')->insert([
                        'enrollment_subject_id' => $enrollmentSubjectId,
                        'grading_period_id' => $periodIds[$periodName],
                        'professor_id' => $professorId,
                        'grade' => $score,
                        'remarks' => 'In Progress',
                        'status' => 'approved',
                        'submitted_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}

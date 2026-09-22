<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SeedDemoMonitoringDataset extends Command
{
    protected $signature = 'demo:seed-dataset';

    protected $description = 'Seed linked demo accounts, enrollments, grades, and monitoring records for testing';

    public function handle(): int
    {
        if (! Schema::hasTable('users')) {
            $this->error('Database tables missing. Run migrations first.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $this->seedRoles();
            $users = $this->seedUsers();
            $profiles = $this->seedProfiles($users);
            $this->seedAcademicCore();
            $this->seedRegistrar($users, $profiles);
            $professorId = $this->seedProfessor($users, $profiles);
            $studentId = $this->seedStudent($users, $profiles);
            $this->seedEnrollmentGradesAndRecords($studentId, $professorId, $users['professor1']);
        });

        $this->info('Demo dataset ready.');
        $this->line('admin / Admin123!');
        $this->line('registrar1 / Registrar123!');
        $this->line('professor1 / Professor123!  (sees student1)');
        $this->line('student1 / Student123!     (Study Studio + weak topics)');

        return self::SUCCESS;
    }

    private function seedRoles(): void
    {
        $roles = [
            1 => ['role_name' => 'Guest', 'description' => 'Public Portal User'],
            2 => ['role_name' => 'Admin', 'description' => 'System Administrator'],
            3 => ['role_name' => 'Registrar Staff', 'description' => 'Registrar Office Staff'],
            4 => ['role_name' => 'Professor', 'description' => 'Faculty Member'],
            5 => ['role_name' => 'Student', 'description' => 'Enrolled Student'],
        ];

        foreach ($roles as $id => $role) {
            DB::table('roles')->updateOrInsert(
                ['id' => $id],
                $role + ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /**
     * @return array<string, object>
     */
    private function seedUsers(): array
    {
        $roleIds = DB::table('roles')->pluck('id', 'role_name');
        $accounts = [
            'ken' => ['password' => 'ken123!', 'role' => 'Guest'],
            'admin' => ['password' => 'Admin123!', 'role' => 'Admin'],
            'registrar1' => ['password' => 'Registrar123!', 'role' => 'Registrar Staff'],
            'professor1' => ['password' => 'Professor123!', 'role' => 'Professor'],
            'student1' => ['password' => 'Student123!', 'role' => 'Student'],
        ];

        $users = [];
        foreach ($accounts as $username => $account) {
            $existing = DB::table('users')->where('username', $username)->first();
            $payload = [
                'password' => Hash::make($account['password']),
                'role_id' => $roleIds[$account['role']],
                'status' => 'active',
                'is_first_login' => false,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('users')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('users')->insert($payload + [
                    'username' => $username,
                    'last_login' => null,
                    'created_at' => now(),
                ]);
            }

            $users[$username] = DB::table('users')->where('username', $username)->first();
            $this->info("User: {$username}");
        }

        return $users;
    }

    /**
     * @param  array<string, object>  $users
     * @return array<string, object>
     */
    private function seedProfiles(array $users): array
    {
        $defs = [
            'ken' => ['Kenneth', null, 'Gallaza', 'ken@example.com'],
            'admin' => ['System', null, 'Administrator', 'admin@cdm.edu.ph'],
            'registrar1' => ['Maria', 'Santos', 'Cruz', 'registrar@cdm.edu.ph'],
            'professor1' => ['Juan', 'Dela', 'Reyes', 'professor@cdm.edu.ph'],
            'student1' => ['John', 'A.', 'Doe', 'student@cdm.edu.ph'],
        ];

        $profiles = [];
        foreach ($defs as $username => [$first, $middle, $last, $email]) {
            $userId = $users[$username]->id;
            $existing = DB::table('user_profiles')->where('user_id', $userId)->first();
            $payload = [
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'suffix' => null,
                'gender' => 'Prefer not to say',
                'birth_date' => null,
                'civil_status' => 'Single',
                'email' => $email,
                'contact_number' => '09170000000',
                'address' => 'Rodriguez, Rizal',
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('user_profiles')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('user_profiles')->insert($payload + [
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            $profiles[$username] = DB::table('user_profiles')->where('user_id', $userId)->first();
        }

        return $profiles;
    }

    private function seedAcademicCore(): void
    {
        DB::table('departments')->updateOrInsert(
            ['department_code' => 'ICS'],
            [
                'department_name' => 'Institute of Computer Studies',
                'description' => 'Computer and Information Technology Programs',
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $departmentId = (int) DB::table('departments')->where('department_code', 'ICS')->value('id');

        DB::table('courses')->updateOrInsert(
            ['course_code' => 'BSIT'],
            [
                'department_id' => $departmentId,
                'course_name' => 'Bachelor of Science in Information Technology',
                'years' => 4,
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $courseId = (int) DB::table('courses')->where('course_code', 'BSIT')->value('id');

        DB::table('curriculums')->updateOrInsert(
            ['curriculum_code' => 'BSIT-2026'],
            [
                'course_id' => $courseId,
                'curriculum_name' => 'BSIT Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        DB::table('academic_years')->updateOrInsert(
            ['school_year' => '2026-2027'],
            [
                'start_date' => '2026-08-01',
                'end_date' => '2027-05-31',
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        foreach ([
            ['First Semester', 1],
            ['Second Semester', 2],
            ['Summer', 3],
        ] as [$name, $order]) {
            DB::table('semesters')->updateOrInsert(
                ['semester_name' => $name],
                [
                    'semester_order' => $order,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        foreach ([
            ['Prelim', 1],
            ['Midterm', 2],
            ['Final', 3],
        ] as [$name, $order]) {
            DB::table('grading_periods')->updateOrInsert(
                ['period_name' => $name],
                [
                    'period_order' => $order,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $curriculumId = (int) DB::table('curriculums')->where('curriculum_code', 'BSIT-2026')->value('id');
        $semesterId = (int) DB::table('semesters')->where('semester_name', 'First Semester')->value('id');

        foreach ([
            ['IT101', 'Introduction to Computing', null],
            ['IT102', 'Computer Programming 1', null],
            ['IT103', 'Discrete Mathematics', null],
        ] as [$code, $name, $prereq]) {
            DB::table('subjects')->updateOrInsert(
                ['subject_code' => $code, 'curriculum_id' => $curriculumId],
                [
                    'semester_id' => $semesterId,
                    'subject_name' => $name,
                    'year_level' => 1,
                    'units' => 3,
                    'lecture_hours' => 2,
                    'laboratory_hours' => 3,
                    'prerequisite_subject_id' => $prereq,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  array<string, object>  $users
     * @param  array<string, object>  $profiles
     */
    private function seedRegistrar(array $users, array $profiles): void
    {
        if (! Schema::hasTable('registrar_staff')) {
            return;
        }

        DB::table('registrar_staff')->updateOrInsert(
            ['employee_number' => 'REG-2026-001'],
            [
                'user_id' => $users['registrar1']->id,
                'user_profile_id' => $profiles['registrar1']->id,
                'position' => 'Registrar Staff',
                'employment_status' => 'regular',
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, object>  $users
     * @param  array<string, object>  $profiles
     */
    private function seedProfessor(array $users, array $profiles): int
    {
        $departmentId = (int) DB::table('departments')->where('department_code', 'ICS')->value('id');

        DB::table('professors')->updateOrInsert(
            ['employee_number' => 'FAC-2026-001'],
            [
                'user_id' => $users['professor1']->id,
                'user_profile_id' => $profiles['professor1']->id,
                'department_id' => $departmentId,
                'position' => 'Instructor I',
                'specialization' => 'Software Development',
                'employment_status' => 'full_time',
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return (int) DB::table('professors')->where('employee_number', 'FAC-2026-001')->value('id');
    }

    /**
     * @param  array<string, object>  $users
     * @param  array<string, object>  $profiles
     */
    private function seedStudent(array $users, array $profiles): int
    {
        $courseId = (int) DB::table('courses')->where('course_code', 'BSIT')->value('id');
        $curriculumId = (int) DB::table('curriculums')->where('curriculum_code', 'BSIT-2026')->value('id');

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
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return (int) DB::table('students')->where('student_number', '26-00001')->value('id');
    }

    private function seedEnrollmentGradesAndRecords(int $studentId, int $professorId, object $professorUser): void
    {
        $courseId = (int) DB::table('courses')->where('course_code', 'BSIT')->value('id');
        $ayId = (int) DB::table('academic_years')->where('school_year', '2026-2027')->value('id');
        $semesterId = (int) DB::table('semesters')->where('semester_name', 'First Semester')->value('id');

        DB::table('sections')->updateOrInsert(
            [
                'course_id' => $courseId,
                'academic_year_id' => $ayId,
                'semester_id' => $semesterId,
                'section_name' => 'BSIT-1A',
            ],
            [
                'year_level' => 1,
                'adviser_id' => $professorId,
                'capacity' => 40,
                'status' => 'open',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $sectionId = (int) DB::table('sections')
            ->where('section_name', 'BSIT-1A')
            ->where('academic_year_id', $ayId)
            ->value('id');

        DB::table('enrollments')->updateOrInsert(
            [
                'student_id' => $studentId,
                'academic_year_id' => $ayId,
                'semester_id' => $semesterId,
            ],
            [
                'section_id' => $sectionId,
                'enrollment_date' => '2026-08-15',
                'status' => 'enrolled',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $enrollmentId = (int) DB::table('enrollments')
            ->where('student_id', $studentId)
            ->where('academic_year_id', $ayId)
            ->where('semester_id', $semesterId)
            ->value('id');

        $subjects = DB::table('subjects')
            ->whereIn('subject_code', ['IT101', 'IT102', 'IT103'])
            ->get()
            ->keyBy('subject_code');

        $gradeMap = [
            'IT101' => ['prelim' => 78, 'midterm' => 74, 'final' => 72],
            'IT102' => ['prelim' => 68, 'midterm' => 65, 'final' => 70],
            'IT103' => ['prelim' => 82, 'midterm' => 79, 'final' => 80],
        ];

        $periods = DB::table('grading_periods')->pluck('id', 'period_name');

        foreach ($subjects as $code => $subject) {
            DB::table('enrollment_subjects')->updateOrInsert(
                [
                    'enrollment_id' => $enrollmentId,
                    'subject_id' => $subject->id,
                ],
                [
                    'professor_id' => $professorId,
                    'subject_status' => 'enrolled',
                    'final_grade' => null,
                    'remarks' => 'In Progress',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $enrollmentSubjectId = (int) DB::table('enrollment_subjects')
                ->where('enrollment_id', $enrollmentId)
                ->where('subject_id', $subject->id)
                ->value('id');

            foreach ([
                'Prelim' => $gradeMap[$code]['prelim'],
                'Midterm' => $gradeMap[$code]['midterm'],
                'Final' => $gradeMap[$code]['final'],
            ] as $periodName => $grade) {
                DB::table('grades')->updateOrInsert(
                    [
                        'enrollment_subject_id' => $enrollmentSubjectId,
                        'grading_period_id' => $periods[$periodName],
                    ],
                    [
                        'professor_id' => $professorId,
                        'grade' => $grade,
                        'remarks' => $grade >= 75 ? 'Passed' : 'Failed',
                        'status' => 'approved',
                        'submitted_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        if (Schema::hasTable('monitoring_performance_records')) {
            $samples = [
                [
                    'subject_code' => 'IT102',
                    'subject_name' => 'Computer Programming 1',
                    'assessment_name' => 'Quiz 2',
                    'topic' => 'Loops and nested conditionals',
                    'score' => 8,
                    'max_score' => 20,
                    'notes' => 'Struggles with loop control and off-by-one errors.',
                ],
                [
                    'subject_code' => 'IT102',
                    'subject_name' => 'Computer Programming 1',
                    'assessment_name' => 'Lab Activity 3',
                    'topic' => 'Arrays and indexing',
                    'score' => 12,
                    'max_score' => 25,
                    'notes' => 'Needs more practice on traversing arrays.',
                ],
                [
                    'subject_code' => 'IT101',
                    'subject_name' => 'Introduction to Computing',
                    'assessment_name' => 'Short Quiz',
                    'topic' => 'Number systems conversion',
                    'score' => 9,
                    'max_score' => 15,
                    'notes' => 'Binary to decimal conversions are inconsistent.',
                ],
            ];

            foreach ($samples as $sample) {
                $exists = DB::table('monitoring_performance_records')
                    ->where('student_id', $studentId)
                    ->where('professor_user_id', $professorUser->id)
                    ->where('assessment_name', $sample['assessment_name'])
                    ->where('topic', $sample['topic'])
                    ->exists();

                if ($exists) {
                    DB::table('monitoring_performance_records')
                        ->where('student_id', $studentId)
                        ->where('professor_user_id', $professorUser->id)
                        ->where('assessment_name', $sample['assessment_name'])
                        ->where('topic', $sample['topic'])
                        ->update($sample + ['updated_at' => now()]);
                } else {
                    DB::table('monitoring_performance_records')->insert($sample + [
                        'student_id' => $studentId,
                        'professor_user_id' => $professorUser->id,
                        'attachment_path' => null,
                        'attachment_name' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('monitoring_sent_plans')) {
            $exists = DB::table('monitoring_sent_plans')
                ->where('student_id', $studentId)
                ->where('title', 'Study plan: Quiz 2 · Loops and nested conditionals')
                ->exists();

            if (! $exists) {
                DB::table('monitoring_sent_plans')->insert([
                    'student_id' => $studentId,
                    'sender_user_id' => $professorUser->id,
                    'performance_record_id' => DB::table('monitoring_performance_records')
                        ->where('student_id', $studentId)
                        ->where('topic', 'Loops and nested conditionals')
                        ->value('id'),
                    'title' => 'Study plan: Quiz 2 · Loops and nested conditionals',
                    'topic' => 'Loops and nested conditionals',
                    'subject_code' => 'IT102',
                    'plan_body' => "Day 1: Review for/while loop syntax.\nDay 2: Trace 5 nested loop examples.\nDay 3: Rewrite Quiz 2 mistakes.\nDay 4: Build 3 practice drills.\nDay 5: Self-quiz and list questions for Prof. Reyes.",
                    'source' => 'cdm-coach',
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}

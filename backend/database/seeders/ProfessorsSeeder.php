<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProfessorsSeeder extends Seeder
{
    private const USERNAME = 'professor1';

    private const EMPLOYEE_NUMBER = 'FAC-2026-001';

    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->with(['role', 'profile'])->where('username', self::USERNAME)->firstOrFail();
            $professorRoleId = Role::query()->where('role_name', Role::PROFESSOR)->value('id');
            if (! $professorRoleId || ! $user->profile) {
                throw new LogicException('professor1 requires the Professor role and a User Profile before ProfessorsSeeder can run.');
            }

            $user->forceFill(['role_id' => $professorRoleId, 'status' => 'active'])->save();
            $department = Department::query()->where('department_code', 'ICS')->where('status', 'active')->first()
                ?? Department::query()->where('status', 'active')->orderBy('id')->first();
            if (! $department) {
                throw new LogicException('An active Department is required for professor1.');
            }

            $linked = Professor::query()->where('user_id', $user->id)->first();
            $seeded = Professor::query()->where('employee_number', self::EMPLOYEE_NUMBER)->first();
            if ($linked && $seeded && ! $linked->is($seeded)) {
                throw new LogicException('professor1 and FAC-2026-001 resolve to different Professor rows; refusing to merge academic identities automatically.');
            }

            $professor = $linked ?? $seeded ?? new Professor;
            $professor->fill([
                'user_id' => $user->id,
                'user_profile_id' => $user->profile->id,
                'department_id' => $department->id,
                'employee_number' => self::EMPLOYEE_NUMBER,
                'position' => 'Instructor I',
                'specialization' => 'Software Development',
                'employment_status' => 'full_time',
                'status' => 'active',
            ])->save();

            if (app()->environment(['local', 'testing'])) {
                $this->ensureGradingClass($professor);
            }

            $this->command?->info("Professor academic identity ready: {$user->username} / {$professor->employee_number}.");
        }, 3);
    }

    private function ensureGradingClass(Professor $professor): void
    {
        $existing = EnrollmentSubject::query()
            ->whereIn('subject_status', ['enrolled', 'completed'])
            ->where(fn ($query) => $query->whereNull('professor_id')->orWhere('professor_id', $professor->id))
            ->whereHas('enrollment', fn ($query) => $query->whereIn('status', ['enrolled', 'completed']))
            ->with('enrollment')
            ->orderBy('id')
            ->get()
            ->first(function (EnrollmentSubject $enrollmentSubject) use ($professor): bool {
                $assignment = SectionSubject::query()
                    ->where('section_id', $enrollmentSubject->enrollment->section_id)
                    ->where('subject_id', $enrollmentSubject->subject_id)
                    ->first();

                return ! $assignment || ! $assignment->professor_id || $assignment->professor_id === $professor->id;
            });

        if ($existing) {
            $this->assign($professor, $existing->enrollment, $existing);

            return;
        }

        $student = Student::query()->whereHas('user', fn ($query) => $query->where('username', 'student1')->where('status', 'active'))->first();
        if (! $student) {
            $this->command?->warn('Professor identity created; student1 is unavailable, so no local Grading class was seeded.');

            return;
        }

        $semesterIds = Semester::query()->where('status', 'active')->pluck('id');
        $subject = Subject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->where('year_level', $student->year_level)
            ->where('status', 'active')
            ->whereIn('semester_id', $semesterIds)
            ->orderBy('id')
            ->first();
        $year = AcademicYear::query()->where('status', 'active')->orderByDesc('start_date')->first();
        if (! $subject || ! $year) {
            $this->command?->warn('Professor identity created; active Student curriculum, Subject, or academic year data is unavailable.');

            return;
        }

        $section = Section::query()
            ->where('course_id', $student->course_id)
            ->where('academic_year_id', $year->id)
            ->where('semester_id', $subject->semester_id)
            ->where('year_level', $student->year_level)
            ->orderBy('id')
            ->first();
        if (! $section) {
            $courseCode = $student->course?->course_code ?? 'CLASS';
            $section = Section::query()->create([
                'course_id' => $student->course_id,
                'academic_year_id' => $year->id,
                'semester_id' => $subject->semester_id,
                'section_name' => $courseCode.'-'.$student->year_level.'A',
                'year_level' => $student->year_level,
                'capacity' => 40,
                'status' => 'open',
            ]);
        }

        $enrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->where('semester_id', $subject->semester_id)
            ->first();
        if ($enrollment && ! in_array($enrollment->status, ['enrolled', 'completed'], true)) {
            throw new LogicException('student1 already has a non-final Enrollment for the seeded Grading term; refusing to overwrite it.');
        }
        if ($enrollment && $enrollment->section_id !== $section->id) {
            $section = $enrollment->section()->firstOrFail();
        }
        $enrollment ??= Enrollment::query()->create([
            'student_id' => $student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
            'semester_id' => $subject->semester_id,
            'enrollment_date' => $year->start_date,
            'status' => 'enrolled',
        ]);

        $enrollmentSubject = EnrollmentSubject::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('subject_id', $subject->id)
            ->first();
        if ($enrollmentSubject && ! in_array($enrollmentSubject->subject_status, ['enrolled', 'completed'], true)) {
            throw new LogicException('student1 already has a non-active Enrollment Subject for the seeded Grading class; refusing to overwrite it.');
        }
        if ($enrollmentSubject && $enrollmentSubject->professor_id && $enrollmentSubject->professor_id !== $professor->id) {
            throw new LogicException('The seeded Grading Enrollment Subject belongs to another Professor; refusing to reassign it.');
        }
        $enrollmentSubject ??= EnrollmentSubject::query()->create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'professor_id' => $professor->id,
            'subject_status' => 'enrolled',
            'remarks' => 'In Progress',
        ]);

        $this->assign($professor, $enrollment, $enrollmentSubject);
    }

    private function assign(Professor $professor, Enrollment $enrollment, EnrollmentSubject $enrollmentSubject): void
    {
        $assignment = SectionSubject::query()
            ->where('section_id', $enrollment->section_id)
            ->where('subject_id', $enrollmentSubject->subject_id)
            ->first();
        if ($assignment && $assignment->professor_id && $assignment->professor_id !== $professor->id) {
            throw new LogicException('The seeded Grading class belongs to another Professor; refusing to reassign it.');
        }

        if (! $assignment) {
            SectionSubject::query()->create([
                'section_id' => $enrollment->section_id,
                'subject_id' => $enrollmentSubject->subject_id,
                'professor_id' => $professor->id,
            ]);
        } elseif (! $assignment->professor_id) {
            $assignment->update(['professor_id' => $professor->id]);
        }

        if (! $enrollmentSubject->professor_id) {
            $enrollmentSubject->update(['professor_id' => $professor->id]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\Admission\AdmissionExamQuestion;
use App\Models\Admission\AdmissionExamResult;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Admission\AdmissionRecommendation;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\EnrollmentSubject;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admission\AdmissionConversionService;
use App\Services\Admission\ProgramMatcher;
use App\Services\Enrollment\EnrollmentAcademicService;
use App\Services\Enrollment\EnrollmentEligibilityService;
use App\Services\Enrollment\EnrollmentSchedulingService;
use App\Services\Enrollment\EnrollmentSubjectPolicy;
use Database\Seeders\LargeDatasetSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PortalDevelopmentSeed extends Command
{
    private const KEY = 'enrollment-admission-demo-v1';

    private ?array $cleanupScope = null;

    private ?array $cleanupForeignKeys = null;

    protected $signature = 'portal:dev-seed {--cleanup : Remove only tracked DEV-ENR demo records} {--refresh : Safely replace fixture people and scenarios while retaining Sections and the period}';

    protected $description = 'Explicit local/testing Admission and Enrollment demo fixture';

    public function handle(AdmissionConversionService $conversion): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('portal:dev-seed is available only in local/testing environments.');

            return self::FAILURE;
        }
        foreach (['generated_data_records', 'enrollment_application_subjects', 'notifications'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing {$table}. Apply the reviewed Enrollment Phase 3 migration first.");

                return self::FAILURE;
            }
        }

        try {
            $summary = DB::transaction(function () use ($conversion) {
                if ($this->option('cleanup')) {
                    return $this->cleanup();
                }
                $cleanup = $this->option('refresh') ? $this->refreshStudents().' ' : '';

                return $cleanup.$this->seed($conversion);
            }, 3);
            $this->info($summary);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function track(string $type, int $id): void
    {
        DB::table('generated_data_records')->insertOrIgnore([
            'dataset_key' => self::KEY, 'record_type' => $type, 'record_id' => $id,
            'record_created_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ids(string $type): array
    {
        if ($this->cleanupScope !== null) {
            return $this->cleanupScope[$type] ?? [];
        }

        return DB::table('generated_data_records')->where('dataset_key', self::KEY)
            ->where('record_type', $type)->pluck('record_id')->all();
    }

    private function seed(AdmissionConversionService $conversion): string
    {
        $year = AcademicYear::where('status', 'active')->orderByDesc('id')->firstOrFail();
        $semester = Semester::where('status', 'active')->orderBy('semester_order')->firstOrFail();
        $courses = Course::with('department')->where('status', 'active')->whereBetween('years', [1, 4])
            ->whereHas('department', fn ($q) => $q->where('status', 'active'))->orderBy('id')->get();
        if ($courses->isEmpty() || ! $courses->contains('course_code', 'BSIT')) {
            throw new \RuntimeException('An active BSIT Course and at least one active Institute/Course are required.');
        }
        $admin = User::where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('role_name', [Role::ADMIN, Role::REGISTRAR_STAFF]))->firstOrFail();
        $studentRole = Role::where('role_name', Role::STUDENT)->firstOrFail();
        $guestRole = Role::where('role_name', Role::GUEST)->firstOrFail();
        $professorRole = Role::where('role_name', Role::PROFESSOR)->firstOrFail();

        $sections = [];
        foreach ($courses as $course) {
            $curriculum = Curriculum::where('course_id', $course->id)->where('status', 'active')
                ->where('effective_year', '<=', now()->year)->orderByDesc('effective_year')->first();
            if (! $curriculum) {
                continue;
            }
            foreach (range(1, min(4, $course->years)) as $level) {
                foreach (range('A', 'D') as $letter) {
                    $name = $course->course_code.'-'.$level.$letter;
                    $section = Section::where('course_id', $course->id)->where('academic_year_id', $year->id)
                        ->where('semester_id', $semester->id)->where('section_name', $name)->first();
                    if (! $section) {
                        $section = Section::create(['course_id' => $course->id, 'academic_year_id' => $year->id,
                            'semester_id' => $semester->id, 'section_name' => $name, 'year_level' => $level,
                            'capacity' => 40, 'status' => 'open']);
                        $this->track('section', $section->id);
                    }
                    if ($section->year_level === $level) {
                        $sections[$course->id][$level][$letter] = $section;
                    }
                }
            }
        }
        if (collect($sections[$courses->firstWhere('course_code', 'BSIT')->id] ?? [])->sum(fn ($letters) => count($letters)) !== 16) {
            throw new \RuntimeException('BSIT lacks a valid active Curriculum or Section configuration.');
        }

        $period = EnrollmentPeriod::where('academic_year_id', $year->id)->where('semester_id', $semester->id)->first();
        if (! $period) {
            $period = EnrollmentPeriod::create(['academic_year_id' => $year->id, 'semester_id' => $semester->id,
                'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(30), 'enabled' => true,
                'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []],
                'created_by' => $admin->id, 'updated_by' => $admin->id]);
            $this->track('period', $period->id);
        }

        $cycle = AdmissionCycle::where('code', 'DEV-ENR')->first();
        if (! $cycle) {
            $cycle = AdmissionCycle::create(['code' => 'DEV-ENR', 'name' => '[DEVELOPMENT ONLY] Enrollment demo Admission',
                'academic_year_id' => $year->id, 'status' => 'open', 'opens_at' => now()->subDay(),
                'closes_at' => now()->addDays(30), 'confirmation_closes_at' => now()->addDays(31),
                'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);
            $this->track('cycle', $cycle->id);
        } elseif (! in_array($cycle->id, $this->ids('cycle'), true)) {
            throw new \RuntimeException('DEV-ENR cycle exists without this demo ledger; refusing to modify it.');
        }

        $bank = collect();
        foreach (ProgramMatcher::INTEREST_CATEGORIES as $topicIndex => $topic) {
            for ($n = 1; $n <= 20; $n++) {
                $code = sprintf('DEV-MVP-ENR-%d-%02d', $topicIndex + 1, $n);
                $question = AdmissionExamQuestion::where('question_code', $code)->first();
                if (! $question) {
                    $question = AdmissionExamQuestion::create(['question_code' => $code, 'topic' => $topic,
                        'question_text' => '[DEVELOPMENT DEMO ONLY] '.$topic.' sample '.$n.'; choose A.',
                        'option_a' => 'Demo answer A', 'option_b' => 'Demo answer B',
                        'option_c' => 'Demo answer C', 'option_d' => 'Demo answer D',
                        'correct_answer' => 'A', 'difficulty' => 'easy', 'status' => 'active',
                        'version' => 1,
                        'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);
                    $this->track('question', $question->id);
                } elseif (! in_array($question->id, $this->ids('question'), true)) {
                    throw new \RuntimeException("Demo question code collision: {$code}.");
                }
                $bank->push($question);
            }
        }

        $professor = Professor::where('employee_number', 'DEV-ENR-P')->first();
        if (! $professor) {
            $professorUser = User::create(['username' => 'dev_enr_professor', 'password' => Hash::make(Str::random(48)),
                'role_id' => $professorRole->id, 'status' => 'active']);
            $this->track('user', $professorUser->id);
            $professorProfile = $professorUser->profile()->create(['first_name' => 'Demo', 'last_name' => 'Professor',
                'gender' => 'Prefer not to say', 'email' => 'dev.enr.professor@example.test']);
            $this->track('profile', $professorProfile->id);
            $professor = Professor::create(['user_id' => $professorUser->id,
                'user_profile_id' => $professorProfile->id, 'department_id' => $courses->first()->department_id,
                'employee_number' => 'DEV-ENR-P', 'status' => 'active']);
            $this->track('professor', $professor->id);
        } elseif (! in_array($professor->id, $this->ids('professor'), true)) {
            throw new \RuntimeException('Demo Professor number collision.');
        }

        $eligible = $courses->filter(fn ($c) => isset($sections[$c->id]))->values();
        // Reuse the actual academic identity; never track it as fixture-owned.
        $existing = Student::with(['user.role', 'user.admissionApplications', 'userProfile', 'course', 'curriculum'])
            ->whereNotIn('id', $this->ids('student'))
            ->whereNotIn('user_id', DB::table('generated_data_records')
                ->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])
                ->where('record_type', LargeDatasetSeeder::GENERATED_USER_RECORD_TYPE)
                ->select('record_id'))
            ->orderBy('id')->get();
        $reused = 0;
        foreach ($existing as $student) {
            if (! $student->user || $student->user->status !== 'active'
                || $student->user->role?->role_name !== Role::STUDENT
                || $student->userProfile?->user_id !== $student->user_id
                || ! trim($student->student_number ?? '') || ! $student->admission_date
                || $student->admission_date->isFuture()
                || ! in_array($student->student_status, ['regular', 'irregular'], true)
                || $student->curriculum?->status !== 'active'
                || $student->curriculum->course_id !== $student->course_id
                || $student->curriculum->effective_year > now()->year
                || ! isset($sections[$student->course_id][$student->year_level])) {
                continue;
            }
            if ($student->user->admissionApplications->isNotEmpty()
                && ! app(EnrollmentEligibilityService::class)->status($student->user)['academic_ready']) {
                continue;
            }
            $reused++;
            $this->seedApplication($student, $period, $admin, $professor,
                $sections[$student->course_id][$student->year_level]['A'], $reused);
        }
        // One Admission conversion example, plus only enough fallbacks for scenario coverage.
        $syntheticTarget = max(1, 8 - $reused);
        for ($i = 1; $i <= $syntheticTarget; $i++) {
            $course = $eligible[($i - 1) % $eligible->count()];
            $curriculum = Curriculum::where('course_id', $course->id)->where('status', 'active')->where('effective_year', '<=', now()->year)->orderByDesc('effective_year')->firstOrFail();
            $courseIndex = intdiv($i - 1, $eligible->count());
            $level = ($i - 1) % min(4, $course->years) + 1;
            if ($i <= 1) {
                $level = 1;
            }
            $letter = range('A', 'D')[($courseIndex + intdiv($courseIndex, 4)) % 4];
            $user = User::where('username', sprintf('dev_enr_%02d', $i))->first();
            if ($user) {
                if (! in_array($user->id, $this->ids('user'), true)) {
                    throw new \RuntimeException("Demo username collision for {$user->username}.");
                }

                continue;
            }
            $user = User::create(['username' => sprintf('dev_enr_%02d', $i), 'password' => Hash::make(Str::random(48)),
                'role_id' => $i <= 1 ? $guestRole->id : $studentRole->id, 'status' => 'active', 'is_first_login' => true]);
            $this->track('user', $user->id);
            $profile = $user->profile()->create(['first_name' => 'Demo', 'last_name' => sprintf('Student %02d', $i),
                'gender' => 'Prefer not to say', 'email' => sprintf('dev.enr.%02d@example.test', $i)]);
            $this->track('profile', $profile->id);

            if ($i <= 1) {
                $applicant = new AdmissionApplicant;
                $applicant->forceFill(['user_id' => $user->id, 'cycle_id' => $cycle->id, 'preferred_course_id' => $course->id])->save();
                $this->track('applicant', $applicant->id);
                $session = new AdmissionExamSession;
                $session->forceFill(['id' => (string) Str::uuid(), 'applicant_id' => $applicant->id,
                    'attempt_number' => 1, 'status' => 'finalized', 'bank_version' => 'DEV-ENR fixture',
                    'policy_snapshot' => [], 'revision' => 1, 'started_at' => now()->subHour(), 'deadline_at' => now()->addHour(),
                    'finalized_at' => now()])->save();
                $this->seedSessionQuestions($session, $bank, 80);
                $result = AdmissionExamResult::create(['session_id' => $session->id, 'raw_correct_count' => 80,
                    'question_count' => 100, 'system_percentage' => 80, 'system_passed' => true,
                    'category_scores' => array_fill_keys(ProgramMatcher::INTEREST_CATEGORIES, 16),
                    'category_maximums' => array_fill_keys(ProgramMatcher::INTEREST_CATEGORIES, 20),
                    'time_spent_seconds' => 3600,
                    'finalized_at' => now(), 'finalization_cause' => 'submit', 'official_score' => 80,
                    'official_status' => 'published', 'published_at' => now(), 'version' => 1]);
                AdmissionRecommendation::create(['result_id' => $result->id, 'result_version' => 1,
                    'input_fingerprint' => hash('sha256', 'DEV-ENR-'.$i), 'interests' => [],
                    'catalog_snapshot' => [], 'matcher_version' => 'dev-demo-1',
                    'generation_status' => 'ready', 'ai_status' => 'not_used',
                    'ranked_programs' => [], 'evidence' => [], 'explanations' => [],
                    'top_course_id' => $course->id, 'generated_at' => now()]);
                $input = ['version' => $applicant->version, 'result_id' => $result->id,
                    'result_version' => $result->version, 'course_id' => $course->id,
                    'curriculum_id' => $curriculum->id, 'confirmed' => true];
                $conversion->accept($admin, $applicant->id, $input);
                $input['version'] = $applicant->fresh()->version;
                $conversion->convert($admin, $applicant->id, $input + [
                    'student_number' => sprintf('DEV-ENR-%02d', $i), 'admission_date' => now()->toDateString()]);
                $student = $user->student;
            } else {
                $student = Student::create(['user_id' => $user->id, 'user_profile_id' => $profile->id,
                    'course_id' => $course->id, 'curriculum_id' => $curriculum->id,
                    'student_number' => sprintf('DEV-ENR-%02d', $i), 'year_level' => $level,
                    'admission_date' => now()->subYears($level - 1)->toDateString(),
                    'student_status' => $i % 7 === 0 ? 'irregular' : 'regular']);
            }
            $this->track('student', $student->id);
            $this->seedApplication($student, $period, $admin, $professor,
                $sections[$course->id][$student->year_level][$letter], $i);
        }

        foreach (['draft', 'active', 'failed', 'passed'] as $case) {
            $username = 'dev_enr_guest_'.$case;
            if (User::where('username', $username)->exists()) {
                continue;
            }
            $guest = User::create(['username' => $username, 'password' => Hash::make(Str::random(48)),
                'role_id' => $guestRole->id, 'status' => 'active']);
            $this->track('user', $guest->id);
            $profile = $guest->profile()->create(['first_name' => 'Demo', 'last_name' => 'Guest '.ucfirst($case),
                'gender' => 'Prefer not to say', 'email' => 'dev.enr.guest.'.$case.'@example.test']);
            $this->track('profile', $profile->id);
            $applicant = new AdmissionApplicant;
            $applicant->forceFill(['user_id' => $guest->id, 'cycle_id' => $cycle->id,
                'preferred_course_id' => $eligible->first()->id])->save();
            $this->track('applicant', $applicant->id);
            if ($case === 'draft') {
                continue;
            }
            $session = new AdmissionExamSession;
            $session->forceFill(['id' => (string) Str::uuid(), 'applicant_id' => $applicant->id,
                'attempt_number' => 1, 'status' => $case === 'active' ? 'active' : 'finalized',
                'bank_version' => 'DEV-ENR fixture', 'policy_snapshot' => [],
                'revision' => in_array($case, ['failed', 'passed'], true) ? 1 : 0,
                'started_at' => now()->subHour(), 'deadline_at' => now()->addHour(),
                'finalized_at' => in_array($case, ['failed', 'passed'], true) ? now() : null])->save();
            $score = ['failed' => 40, 'passed' => 80][$case] ?? null;
            $this->seedSessionQuestions($session, $bank, $score);
            if ($score !== null) {
                AdmissionExamResult::create(['session_id' => $session->id, 'raw_correct_count' => $score,
                    'question_count' => 100, 'system_percentage' => $score, 'system_passed' => $score >= 75,
                    'category_scores' => array_fill_keys(ProgramMatcher::INTEREST_CATEGORIES, $score / 5),
                    'category_maximums' => array_fill_keys(ProgramMatcher::INTEREST_CATEGORIES, 20),
                    'time_spent_seconds' => 3600,
                    'finalized_at' => now(), 'finalization_cause' => 'submit', 'official_score' => $score,
                    'official_status' => 'published', 'published_at' => now(), 'version' => 1]);
            }
        }

        $coverage = Subject::where('semester_id', $semester->id)->where('status', 'active')
            ->whereHas('curriculum', fn ($q) => $q->where('status', 'active')->where('effective_year', '<=', now()->year))
            ->with('curriculum')->get()->groupBy(fn ($s) => $s->curriculum->course_id.':'.$s->year_level);
        foreach ($eligible as $course) {
            $missing = collect(array_keys($sections[$course->id]))->filter(fn ($level) => ! $coverage->has($course->id.':'.$level));
            if ($missing->isNotEmpty()) {
                $this->warn($course->course_code.' has no active curriculum Subjects in this term for year(s): '.$missing->implode(', ').'. No Subjects fabricated.');
            }
        }
        $converted = DB::table('admission_applicants')->whereIn('id', $this->ids('applicant'))->where('status', 'converted')->count();

        return 'DEV-ENR fixture ready: '.$reused.' existing Students reused, '.count($this->ids('student')).' synthetic Student accounts ('.$converted.' converted Admission identities), '.
            count($this->ids('section')).' tracked Sections. Institutes/Courses: '.
            $eligible->map(fn ($c) => $c->department?->department_code.'/'.$c->course_code)->implode(', ').'.';
    }

    private function seedApplication(Student $student, EnrollmentPeriod $period, User $admin, Professor $professor, Section $section, int $i): void
    {
        if (EnrollmentApplication::where('student_id', $student->id)->where('academic_year_id', $period->academic_year_id)->where('semester_id', $period->semester_id)->exists()
            || Enrollment::where('student_id', $student->id)->where('academic_year_id', $period->academic_year_id)->where('semester_id', $period->semester_id)->exists()) {
            return;
        }
        $classification = ['regular', 'irregular', 'transferee', 'returnee'][($i - 1) % 4];
        $status = ['draft', 'submitted', 'under_review', 'approved', 'enrolled', 'rejected'][($i - 1) % 6];
        $application = EnrollmentApplication::create(['student_id' => $student->id, 'period_id' => $period->id,
            'academic_year_id' => $period->academic_year_id, 'semester_id' => $period->semester_id,
            'course_id' => $student->course_id, 'curriculum_id' => $student->curriculum_id,
            'year_level' => $student->year_level, 'classification' => $classification, 'status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
            'reviewed_at' => in_array($status, ['approved', 'enrolled', 'rejected']) ? now() : null,
            'reviewed_by' => in_array($status, ['approved', 'enrolled', 'rejected']) ? $admin->id : null,
            'section_id' => null,
            'load_reviewed_at' => $status === 'enrolled' ? now() : null,
            'load_reviewed_by' => $status === 'enrolled' ? $admin->id : null]);
        $this->track('application', $application->id);
        if ($status !== 'enrolled') {
            return;
        }
        $subjects = Subject::where('curriculum_id', $student->curriculum_id)->where('semester_id', $period->semester_id)
            ->where('year_level', $student->year_level)->where('status', 'active')->whereNull('prerequisite_subject_id')
            ->orderBy('id')->get();
        try {
            app(EnrollmentSubjectPolicy::class)->validate($application, $subjects->pluck('id')->all());
        } catch (HttpException $e) {
            $subjects = collect();
        }
        if ($subjects->isEmpty() || count($this->ids('enrollment')) >= 8
            || $section->status !== 'open'
            || app(EnrollmentAcademicService::class)->occupied($section) >= $section->capacity) {
            $application->update(['status' => 'approved', 'load_reviewed_at' => null, 'load_reviewed_by' => null]);

            return;
        }
        $enrollment = Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id,
            'academic_year_id' => $period->academic_year_id, 'semester_id' => $period->semester_id,
            'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']);
        $this->track('enrollment', $enrollment->id);
        foreach ($subjects as $subject) {
            $slot = count($this->ids('schedule'));
            $startHour = 8 + intdiv($slot, 7);
            $day = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$slot % 7];
            $schedule = SectionSubject::where('section_id', $section->id)->where('subject_id', $subject->id)->first();
            if (! $schedule) {
                $schedule = SectionSubject::create(['section_id' => $section->id, 'subject_id' => $subject->id,
                    'professor_id' => $professor->id, 'day' => $day,
                    'start_time' => sprintf('%02d:00:00', $startHour),
                    'end_time' => sprintf('%02d:00:00', $startHour + 1),
                    'room' => 'DEV-ENR ROOM '.$section->id]);
                $this->track('schedule', $schedule->id);
            }
            $application->subjects()->attach($subject->id);
            EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'subject_id' => $subject->id,
                'professor_id' => $schedule->professor_id, 'subject_status' => 'enrolled', 'remarks' => 'In Progress']);
        }
        app(EnrollmentSchedulingService::class)->assertFinalSchedules($section->schedules()->whereIn('subject_id', $subjects->pluck('id'))->get(), $period->academic_year_id, $period->semester_id);
        $application->update(['section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'finalized_at' => now()]);
    }

    private function seedSessionQuestions(AdmissionExamSession $session, $bank, ?int $correct): void
    {
        foreach ($bank as $position => $question) {
            $snapshot = DB::table('admission_exam_session_questions')->insertGetId([
                'session_id' => $session->id, 'question_id' => $question->id,
                'position' => $position + 1, 'topic' => $question->topic,
                'question_version' => $question->version, 'question_text' => $question->question_text,
                'options' => json_encode(['A' => $question->option_a, 'B' => $question->option_b,
                    'C' => $question->option_c, 'D' => $question->option_d]),
                'correct_answer' => 'A', 'created_at' => now(),
            ]);
            if ($correct !== null) {
                DB::table('admission_exam_answers')->insert([
                    'session_question_id' => $snapshot, 'selected_option' => $position % 20 < $correct / 5 ? 'A' : 'B',
                    'accepted_revision' => 1, 'saved_at' => now(), 'is_correct' => $position % 20 < $correct / 5,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function refreshStudents(): string
    {
        $removed = 0;
        $removedApplications = 0;
        $protected = 0;
        $ledger = [];
        foreach (['student', 'user', 'profile', 'applicant', 'application', 'enrollment'] as $type) {
            $ledger[$type] = $this->ids($type);
        }
        foreach (Student::whereIn('id', $ledger['student'])->get() as $student) {
            $scope = [
                'student' => [$student->id],
                'user' => array_values(array_intersect([$student->user_id], $ledger['user'])),
                'profile' => array_values(array_intersect([$student->user_profile_id], $ledger['profile'])),
                'applicant' => AdmissionApplicant::where('user_id', $student->user_id)->whereIn('id', $ledger['applicant'])->pluck('id')->all(),
                'application' => EnrollmentApplication::where('student_id', $student->id)->whereIn('id', $ledger['application'])->pluck('id')->all(),
                'enrollment' => Enrollment::where('student_id', $student->id)->whereIn('id', $ledger['enrollment'])->pluck('id')->all(),
            ];
            $this->cleanupScope = $scope;
            try {
                DB::transaction(fn () => $this->cleanup(true));
                $removed++;
                $removedApplications += count($scope['application']);
            } catch (\Throwable $e) {
                $protected++;
                $this->warn('Retained tracked Student #'.$student->id.': '.$e->getMessage());
            } finally {
                $this->cleanupScope = null;
            }
        }

        return "Removed {$removed} untouched synthetic Students and {$removedApplications} applications; retained {$protected} protected Students. Academic infrastructure retained.";
    }

    private function assertCleanupReferences(bool $retainInfrastructure): void
    {
        // Check inbound foreign keys before any cascades can erase unowned activity.
        $owned = [];
        foreach (['users' => 'user', 'user_profiles' => 'profile', 'students' => 'student',
            'professors' => 'professor', 'admission_applicants' => 'applicant',
            'admission_cycles' => 'cycle', 'admission_exam_questions' => 'question',
            'enrollment_applications' => 'application', 'enrollments' => 'enrollment',
            'section_subjects' => 'schedule'] as $table => $type) {
            $owned[$table] = $this->ids($type);
        }
        if (! $retainInfrastructure) {
            $owned['sections'] = $this->ids('section');
            $owned['enrollment_periods'] = $this->ids('period');
        }
        $children = [
            'admission_exam_sessions' => ['applicant_id', 'admission_applicants'],
            'admission_exam_results' => ['session_id', 'admission_exam_sessions'],
            'admission_exam_session_questions' => ['session_id', 'admission_exam_sessions'],
            'admission_exam_answers' => ['session_question_id', 'admission_exam_session_questions'],
            'admission_recommendations' => ['result_id', 'admission_exam_results'],
            'admission_decisions' => ['applicant_id', 'admission_applicants'],
            'admission_workflow_events' => ['applicant_id', 'admission_applicants'],
            'admission_audit_events' => ['applicant_id', 'admission_applicants'],
            'enrollment_subjects' => ['enrollment_id', 'enrollments'],
        ];
        foreach ($children as $table => [$column, $parent]) {
            $owned[$table] = DB::table($table)->whereIn($column, $owned[$parent])->pluck('id')->all();
        }
        if (DB::table('enrollment_workflow_events')->where('subject_type', 'application')->whereIn('subject_id', $owned['enrollment_applications'])->exists()) {
            throw new \RuntimeException('Tracked applications have subsequent workflow activity; cleanup refused.');
        }
        if ($this->cleanupForeignKeys === null) {
            $this->cleanupForeignKeys = [];
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                $this->cleanupForeignKeys[$table] = Schema::getForeignKeys($table);
            }
        }
        foreach ($this->cleanupForeignKeys as $table => $keys) {
            foreach ($keys as $key) {
                $parent = $key['foreign_table'];
                if (empty($owned[$parent]) || count($key['columns']) !== 1 || $key['foreign_columns'] !== ['id']) {
                    continue;
                }
                $query = DB::table($table)->whereIn($key['columns'][0], $owned[$parent]);
                if ($table === 'enrollment_application_subjects') {
                    $query->whereNotIn('application_id', $owned['enrollment_applications']);
                } elseif (isset($owned[$table])) {
                    $query->whereNotIn('id', $owned[$table]);
                }
                if ($query->exists()) {
                    throw new \RuntimeException("Unowned {$table} activity references tracked {$parent}; cleanup refused.");
                }
            }
        }
    }

    private function cleanup(bool $retainInfrastructure = false): string
    {
        $users = $this->ids('user');
        $students = $this->ids('student');
        $applicants = $this->ids('applicant');
        $sections = $this->ids('section');
        $professors = $this->ids('professor');
        if (DB::table('users')->whereIn('id', $users)->pluck('username')->contains(fn ($name) => ! str_starts_with($name, 'dev_enr_'))) {
            throw new \RuntimeException('A tracked account no longer has its demo marker; cleanup refused.');
        }
        if ($students && (DB::table('document_requests')->whereIn('student_id', $students)->exists()
            || DB::table('student_documents')->whereIn('student_id', $students)->exists()
            || DB::table('appointments')->whereIn('student_id', $students)->exists())) {
            throw new \RuntimeException('A demo Student has non-demo activity. Cleanup refused.');
        }
        if (! $retainInfrastructure && $sections && DB::table('enrollments')->whereIn('section_id', $sections)->whereNotIn('id', $this->ids('enrollment'))->exists()) {
            throw new \RuntimeException('A demo Section contains a non-demo enrollment. Cleanup refused.');
        }
        if ($professors && (DB::table('sections')->whereIn('adviser_id', $professors)->whereNotIn('id', $sections)->exists()
            || DB::table('section_subjects')->whereIn('professor_id', $professors)->whereNotIn('id', $this->ids('schedule'))->exists())) {
            throw new \RuntimeException('The demo Professor is assigned to non-demo academics. Cleanup refused.');
        }
        $applications = $this->ids('application');
        if (DB::table('enrollment_workflow_events')->whereIn('actor_user_id', $users)
            ->where(fn ($q) => $q->where('subject_type', '!=', 'application')->orWhereNotIn('subject_id', $applications))->exists()) {
            throw new \RuntimeException('A demo account has non-demo Enrollment activity. Cleanup refused.');
        }
        $this->assertCleanupReferences($retainInfrastructure);
        DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users)->delete();
        DB::table('enrollment_workflow_events')->where('subject_type', 'application')->whereIn('subject_id', $applications)->delete();
        DB::table('enrollment_applications')->whereIn('id', $this->ids('application'))->update(['enrollment_id' => null]);
        DB::table('enrollment_application_subjects')->whereIn('application_id', $this->ids('application'))->delete();
        DB::table('enrollment_applications')->whereIn('id', $this->ids('application'))->delete();
        DB::table('enrollment_subjects')->whereIn('enrollment_id', $this->ids('enrollment'))->delete();
        DB::table('enrollments')->whereIn('id', $this->ids('enrollment'))->delete();
        DB::table('section_subjects')->whereIn('id', $this->ids('schedule'))->delete();
        DB::table('admission_decisions')->whereIn('applicant_id', $applicants)->delete();
        DB::table('admission_workflow_events')->whereIn('applicant_id', $applicants)->delete();
        DB::table('admission_audit_events')->whereIn('applicant_id', $applicants)->delete();
        $sessionIds = DB::table('admission_exam_sessions')->whereIn('applicant_id', $applicants)->pluck('id');
        DB::table('admission_recommendations')->whereIn('result_id', DB::table('admission_exam_results')->whereIn('session_id', $sessionIds)->pluck('id'))->delete();
        DB::table('admission_exam_results')->whereIn('session_id', $sessionIds)->delete();
        DB::table('admission_exam_answers')->whereIn('session_question_id', DB::table('admission_exam_session_questions')->whereIn('session_id', $sessionIds)->pluck('id'))->delete();
        DB::table('admission_exam_session_questions')->whereIn('session_id', $sessionIds)->delete();
        DB::table('admission_exam_sessions')->whereIn('id', $sessionIds)->delete();
        DB::table('admission_exam_questions')->whereIn('id', $this->ids('question'))->delete();
        DB::table('admission_applicants')->whereIn('id', $applicants)->delete();
        DB::table('students')->whereIn('id', $students)->delete();
        DB::table('professors')->whereIn('id', $this->ids('professor'))->delete();
        DB::table('user_profiles')->whereIn('id', $this->ids('profile'))->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
        if (! $retainInfrastructure) {
            DB::table('sections')->whereIn('id', $sections)->delete();
            DB::table('enrollment_periods')->whereIn('id', $this->ids('period'))->delete();
        }
        DB::table('admission_cycles')->whereIn('id', $this->ids('cycle'))->delete();
        if ($this->cleanupScope !== null) {
            foreach ($this->cleanupScope as $type => $ids) {
                DB::table('generated_data_records')->where('dataset_key', self::KEY)->where('record_type', $type)->whereIn('record_id', $ids)->delete();
            }
        } else {
            DB::table('generated_data_records')->where('dataset_key', self::KEY)->when($retainInfrastructure, fn ($q) => $q->whereNotIn('record_type', ['section', 'period']))->delete();
        }

        return 'Removed '.count($students).' tracked synthetic Students and '.count($applications).' tracked applications. '.($retainInfrastructure ? 'Sections and period retained.' : 'Only tracked DEV-ENR fixture rows were removed.');
    }
}

<?php

namespace App\Services\Grading;

use App\Models\AcademicYear;
use App\Models\EnrollmentSubject;
use App\Models\GradeConversation;
use App\Models\GradeSheet;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentGradeService
{
    public function __construct(private readonly GradingService $grading) {}

    public function report(User $actor, array $filters = []): array
    {
        $actor->loadMissing(['role', 'student']);
        abort_unless($actor->status === 'active' && $actor->role?->role_name === Role::STUDENT && $actor->student, 403, 'Active Student access required.');

        return $this->forStudent($actor->student, $filters, $actor);
    }

    public function forStudent(Student $student, array $filters = [], ?User $viewer = null): array
    {
        $sheets = GradeSheet::query()
            ->where('status', 'published')
            ->whereHas('latestSubmission.students', fn ($query) => $query->where('student_id', $student->id))
            ->with([
                'latestSubmission.students' => fn ($query) => $query->where('student_id', $student->id),
                'sectionSubject.subject',
                'sectionSubject.section.academicYear',
                'sectionSubject.section.semester',
                'sectionSubject.section.course',
                'professor.user.profile',
            ])->get();

        $conversations = GradeConversation::query()
            ->where('student_id', $student->id)
            ->whereIn('grade_sheet_id', $sheets->pluck('id'))
            ->withCount(['messages as unread_count' => fn ($query) => $viewer
                ? $query->where('sender_user_id', '!=', $viewer->id)->whereNull('read_at')->whereNull('unsent_at')
                : $query->whereRaw('1 = 0')])
            ->get()->keyBy('grade_sheet_id');

        $rows = $sheets->map(function (GradeSheet $sheet) use ($conversations): array {
            $attempt = $sheet->latestSubmission;
            $result = $attempt->students->first();
            $context = $this->context($sheet);
            $conversation = $conversations->get($sheet->id);

            return [
                'grade_sheet_id' => $sheet->id,
                'academic_year_id' => $context['academic_year_id'],
                'academic_year' => $context['academic_year'],
                'semester_id' => $context['semester_id'],
                'semester' => $context['semester'],
                'course_id' => $context['course_id'],
                'course' => $context['course'],
                'year_level' => $context['year_level'],
                'section_id' => $context['section_id'],
                'section' => $context['section'],
                'subject_id' => $context['subject_id'],
                'subject_code' => $context['subject_code'],
                'subject_name' => $context['subject_name'],
                'units' => (float) $context['units'],
                'professor_id' => $context['professor_id'],
                'professor' => $context['professor'],
                'midterm_grade' => $result->midterm_grade,
                'finals_grade' => $result->finals_grade,
                'final_grade' => $result->final_grade,
                'grade_point' => $result->grade_point,
                'remarks' => $result->remarks,
                'published_at' => $sheet->published_at?->toIso8601String(),
                'conversation_id' => $conversation?->id,
                'unread_messages' => (int) ($conversation?->unread_count ?? 0),
                'historical_context' => isset($attempt->configuration['context']) ? 'snapshot' : 'legacy_current_reference',
            ];
        });

        $terms = $this->terms($student, $rows);
        $selected = $this->selectedTerm($terms, $filters);
        $selectedRows = $selected ? $rows->where('academic_year_id', $selected['academic_year_id'])->where('semester_id', $selected['semester_id'])->values() : collect();
        $expected = $selected ? $this->expectedSubjects($student, $selected['academic_year_id'], $selected['semester_id']) : collect();
        $publishedIds = $selectedRows->pluck('grade_sheet_id')->count();
        $expectedIds = $expected->pluck('id')->unique();
        $publishedEnrollmentSubjects = $sheets->filter(function (GradeSheet $sheet) use ($selected): bool {
            if (! $selected) {
                return false;
            }
            $context = $this->context($sheet);

            return (int) $context['academic_year_id'] === (int) $selected['academic_year_id'] && (int) $context['semester_id'] === (int) $selected['semester_id'];
        })->map(fn (GradeSheet $sheet) => $sheet->latestSubmission->students->first()?->enrollment_subject_id)->filter()->unique();
        $publishedExpectedCount = $expectedIds->intersect($publishedEnrollmentSubjects)->count();
        $completion = $selectedRows->isEmpty()
            ? ($expected->isEmpty() ? 'no_published_grades' : 'incomplete')
            : (($expected->isNotEmpty() && $publishedExpectedCount === $expectedIds->count()) ? 'complete' : 'incomplete');

        return [
            'student' => ['id' => $student->id, 'student_number' => $student->student_number, 'status' => $student->student_status],
            'terms' => $terms->values(),
            'selected_term' => $selected,
            'summary' => [
                'completion' => $completion,
                'published_subjects' => $publishedIds,
                'expected_subjects' => $expectedIds->count(),
                'total_units' => (float) $expected->sum(fn (EnrollmentSubject $row) => $row->subject?->units ?? 0),
                'gwa' => ['available' => false, 'value' => null, 'message' => 'Not available until grading scale is configured.'],
            ],
            'grades' => $selectedRows,
        ];
    }

    public function studentCsv(User $actor, array $filters = [], ?Student $student = null): StreamedResponse
    {
        $student ??= $actor->student;
        abort_unless($student, 404, 'Student identity not found.');
        $report = $this->forStudent($student, $filters, $actor->role?->role_name === Role::STUDENT ? $actor : null);
        $term = $report['selected_term'];
        $this->grading->audit($actor, 'grade_report.exported', null, null, ['scope' => $student->user_id === $actor->id ? 'own_history' : 'staff_student_history', 'student_id' => $student->id, 'academic_year_id' => $term['academic_year_id'] ?? null, 'semester_id' => $term['semester_id'] ?? null]);
        $filename = 'academic-grade-summary-'.Str::slug($student->student_number).'.csv';

        return response()->streamDownload(function () use ($report, $student): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Colegio de Montalban']);
            fputcsv($out, ['Academic Grade Summary']);
            fputcsv($out, ['Student Number', $student->student_number]);
            fputcsv($out, ['Academic Term', ($report['selected_term']['academic_year'] ?? 'Not selected').' · '.($report['selected_term']['semester'] ?? 'Not selected')]);
            fputcsv($out, ['Completion', ucfirst(str_replace('_', ' ', $report['summary']['completion']))]);
            fputcsv($out, ['GWA', $report['summary']['gwa']['message']]);
            fputcsv($out, ['Generated At', now()->toIso8601String()]);
            fputcsv($out, []);
            fputcsv($out, ['Subject Code', 'Subject Description', 'Professor', 'Section', 'Units', 'Midterm', 'Finals', 'Final Grade', 'Grade Point', 'Remarks', 'Published At']);
            foreach ($report['grades'] as $row) {
                fputcsv($out, [$row['subject_code'], $row['subject_name'], $row['professor'], $row['section'], $row['units'], $row['midterm_grade'], $row['finals_grade'], $row['final_grade'], $row['grade_point'] ?? 'N/A', $row['remarks'] ?? 'N/A', $row['published_at']]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function classCsv(User $actor, GradeSheet $sheet): StreamedResponse
    {
        abort_unless(in_array($sheet->status, ['approved', 'published'], true), 409, 'Only Approved or Published grade sheets can be exported.');
        $sheet->load(['latestSubmission.students', 'sectionSubject.subject', 'sectionSubject.section.academicYear', 'sectionSubject.section.semester', 'sectionSubject.section.course', 'professor.user.profile']);
        $attempt = $sheet->latestSubmission;
        abort_unless($attempt, 422, 'The grade sheet has no official submission snapshot.');
        $context = $this->context($sheet);
        $this->grading->audit($actor, 'grade_report.exported', $sheet, null, ['scope' => 'class_sheet', 'status' => $sheet->status]);
        $filename = Str::slug(($context['subject_code'] ?: 'class').'-'.($context['section'] ?: 'section').'-grade-sheet').'.csv';

        return response()->streamDownload(function () use ($attempt, $context, $actor): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Colegio de Montalban']);
            fputcsv($out, ['Class Grade Sheet']);
            foreach ([['Academic Year', $context['academic_year']], ['Semester', $context['semester']], ['Subject', $context['subject_code'].' — '.$context['subject_name']], ['Section', $context['section']], ['Professor', $context['professor']], ['Generated At', now()->toIso8601String()], ['Generated By', $actor->username]] as $line) {
                fputcsv($out, $line);
            }
            fputcsv($out, []);
            fputcsv($out, ['Student Number', 'Student Name', 'Midterm', 'Finals', 'Final Grade', 'Grade Point', 'Remarks']);
            foreach ($attempt->students as $row) {
                fputcsv($out, [$row->student_number, $row->student_name, $row->midterm_grade, $row->finals_grade, $row->final_grade, $row->grade_point ?? 'N/A', $row->remarks ?? 'N/A']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function context(GradeSheet $sheet): array
    {
        $snapshot = $sheet->latestSubmission?->configuration['context'] ?? null;
        if (is_array($snapshot)) {
            return $snapshot;
        }
        $assignment = $sheet->sectionSubject;
        $section = $assignment->section;
        $profile = $sheet->professor?->user?->profile;

        return ['academic_year_id' => $section->academic_year_id, 'academic_year' => $section->academicYear?->school_year, 'semester_id' => $section->semester_id, 'semester' => $section->semester?->semester_name, 'course_id' => $section->course_id, 'course' => $section->course?->course_code ?? $section->course?->course_name, 'year_level' => $section->year_level, 'section_id' => $section->id, 'section' => $section->section_name, 'subject_id' => $assignment->subject_id, 'subject_code' => $assignment->subject?->subject_code, 'subject_name' => $assignment->subject?->subject_name, 'units' => (float) ($assignment->subject?->units ?? 0), 'professor_id' => $sheet->professor_id, 'professor' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? ''))];
    }

    private function terms(Student $student, Collection $rows): Collection
    {
        $enrollmentTerms = collect($student->enrollments()->whereIn('status', ['enrolled', 'completed'])->with(['academicYear', 'semester'])->get()->map(fn ($row) => ['academic_year_id' => $row->academic_year_id, 'academic_year' => $row->academicYear?->school_year, 'semester_id' => $row->semester_id, 'semester' => $row->semester?->semester_name])->all());
        $publishedTerms = collect($rows->map(fn ($row) => collect($row)->only(['academic_year_id', 'academic_year', 'semester_id', 'semester'])->all())->all());
        $terms = $publishedTerms->merge($enrollmentTerms)->unique(fn ($term) => $term['academic_year_id'].':'.$term['semester_id']);
        $years = AcademicYear::whereIn('id', $terms->pluck('academic_year_id')->filter())->get()->keyBy('id');
        $semesters = Semester::whereIn('id', $terms->pluck('semester_id')->filter())->get()->keyBy('id');

        return $terms->sortByDesc(fn ($term) => sprintf('%010d-%05d', strtotime((string) ($years->get($term['academic_year_id'])?->start_date ?? '')) ?: 0, $semesters->get($term['semester_id'])?->semester_order ?? 0))->values();
    }

    private function selectedTerm(Collection $terms, array $filters): ?array
    {
        $selected = $terms->first(function ($term) use ($filters): bool {
            return (empty($filters['academic_year_id']) || (int) $term['academic_year_id'] === (int) $filters['academic_year_id'])
                && (empty($filters['semester_id']) || (int) $term['semester_id'] === (int) $filters['semester_id']);
        });

        return $selected ?: null;
    }

    private function expectedSubjects(Student $student, int $year, int $semester): Collection
    {
        return EnrollmentSubject::query()->with('subject')->whereHas('enrollment', fn ($query) => $query->where('student_id', $student->id)->where('academic_year_id', $year)->where('semester_id', $semester)->whereIn('status', ['enrolled', 'completed']))->whereIn('subject_status', ['enrolled', 'completed'])->get();
    }
}

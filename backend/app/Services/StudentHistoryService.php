<?php

namespace App\Services;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionDecision;
use App\Models\Admission\AdmissionExamSession;
use App\Models\Admission\AdmissionRecommendation;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Student;

class StudentHistoryService
{
    public function show(Student $student): array
    {
        $admission = AdmissionApplicant::with(['cycle.academicYear'])
            ->where('converted_student_id', $student->id)
            ->where('user_id', $student->user_id)
            ->latest('id')->first();
        $session = $admission ? AdmissionExamSession::with('result')
            ->where('applicant_id', $admission->id)->orderByDesc('attempt_number')->first() : null;
        $result = $session?->result;
        $recommendation = $result ? AdmissionRecommendation::where('result_id', $result->id)->latest('id')->first() : null;
        $decision = $admission ? AdmissionDecision::where('applicant_id', $admission->id)->latest('id')->first() : null;

        $applications = EnrollmentApplication::with(['period.academicYear', 'period.semester', 'section', 'course', 'subjects'])
            ->where('student_id', $student->id)
            ->join('academic_years', 'academic_years.id', '=', 'enrollment_applications.academic_year_id')
            ->join('semesters', 'semesters.id', '=', 'enrollment_applications.semester_id')
            ->select('enrollment_applications.*')->orderByDesc('academic_years.start_date')
            ->orderByDesc('semesters.semester_order')->orderByDesc('enrollment_applications.id')->get();
        $enrollments = Enrollment::with(['academicYear', 'semester', 'section', 'enrollmentSubjects.subject'])
            ->where('student_id', $student->id)
            ->join('academic_years', 'academic_years.id', '=', 'enrollments.academic_year_id')
            ->join('semesters', 'semesters.id', '=', 'enrollments.semester_id')
            ->select('enrollments.*')->orderByDesc('academic_years.start_date')
            ->orderByDesc('semesters.semester_order')->orderByDesc('enrollments.enrollment_date')->get();

        $current = $student->currentEnrollment;
        $currentApplication = $current?->application;
        $assignedApplication = $student->currentEnrollmentApplication;
        $rank = function ($year, $semester, $period): array {
            $open = $period?->enabled && $period->opens_at?->lte(now()) && $period->closes_at?->gte(now());

            return [$open ? 2 : (($year?->status === 'active' && $semester?->status === 'active') ? 1 : 0),
                (string) ($year?->start_date ?? ''), (int) ($semester?->semester_order ?? 0)];
        };
        $enrollmentRank = $current ? $rank($current->academicYear, $current->semester, $currentApplication?->period) : [-1, '', 0];
        $applicationRank = $assignedApplication ? $rank($assignedApplication->academicYear, $assignedApplication->semester, $assignedApplication->period) : [-1, '', 0];
        $useAssignment = $assignedApplication && (! $current || $applicationRank > $enrollmentRank);
        $overviewApplication = $useAssignment ? $assignedApplication : $currentApplication;
        $overviewSection = $useAssignment ? $assignedApplication->section : $current?->section;

        return [
            'overview' => [
                'student_number' => $student->student_number,
                'course' => $overviewApplication?->course?->course_name ?? $student->course?->course_name,
                'curriculum' => $overviewApplication?->curriculum?->curriculum_name ?? $student->curriculum?->curriculum_name,
                'year_level' => $overviewApplication?->year_level ?? $student->year_level,
                'section' => $overviewSection?->section_name,
                'section_state' => $useAssignment ? 'assigned_pending_finalization' : ($current ? 'official' : 'not_assigned'),
                'academic_status' => $student->student_status,
                'enrollment_status' => $useAssignment ? $assignedApplication->status : $current?->status,
            ],
            'entry_classification' => $admission ? 'Freshman' : (in_array($applications->first()?->classification, ['transferee', 'returnee'], true)
                ? ucfirst($applications->first()->classification) : ($student->year_level > 1 ? 'Continuing' : 'Legacy / Existing Student')),
            'admission' => $admission ? [
                'applicant_number' => $admission->applicant_number,
                'cycle' => $admission->cycle?->name,
                'cycle_year' => $admission->cycle?->academicYear?->school_year,
                'status' => $admission->status,
                'exam_attempt' => $session?->attempt_number,
                'exam_status' => $session?->status,
                'result' => $result?->official_status === 'published' ? $result->outcome() : 'Unpublished',
                'recommendation' => $recommendation?->top_course_id ? Course::whereKey($recommendation->top_course_id)->value('course_name') : $recommendation?->generation_status,
                'accepted_course' => $admission->accepted_course_id ? Course::whereKey($admission->accepted_course_id)->value('course_name') : null,
                'decision' => $decision?->action,
                'converted_at' => $admission->converted_at?->toISOString(),
            ] : null,
            'applications' => $applications->map(fn ($a) => [
                'id' => $a->id, 'classification' => $a->classification, 'status' => $a->status,
                'academic_year' => $a->period?->academicYear?->school_year,
                'semester' => $a->period?->semester?->semester_name,
                'course' => $a->course?->course_name,
                'section' => $a->section?->section_name,
                'finalized_at' => $a->finalized_at,
                'enrollment_id' => $a->enrollment_id,
                'subjects' => $a->subjects->map(fn ($s) => ['code' => $s->subject_code, 'name' => $s->subject_name, 'units' => (float) $s->units]),
                'total_units' => (float) $a->subjects->sum('units'),
            ]),
            'academic_history' => $enrollments->map(fn ($e) => [
                'id' => $e->id, 'academic_year' => $e->academicYear?->school_year,
                'semester' => $e->semester?->semester_name, 'section' => $e->section?->section_name,
                'status' => $e->status, 'enrollment_date' => $e->enrollment_date,
                'subjects' => $e->enrollmentSubjects->map(fn ($s) => [
                    'code' => $s->subject?->subject_code, 'name' => $s->subject?->subject_name,
                    'units' => (float) ($s->subject?->units ?? 0), 'status' => $s->subject_status,
                ]),
                'total_units' => (float) $e->enrollmentSubjects->sum(fn ($s) => $s->subject?->units ?? 0),
            ]),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EnrollmentSubject;
use App\Models\Event;
use App\Models\GradeSheet;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\Student;
use App\Services\EarlyWarningService;
use App\Services\Grading\GradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSummaryController extends Controller
{
    public function student(Request $request): JsonResponse
    {
        $actor = $request->user()->loadMissing(['role', 'profile']);
        abort_unless($actor->role?->role_name === Role::STUDENT, 403, 'Student dashboard access required.');

        $student = Student::query()
            ->with(['userProfile', 'course'])
            ->where('user_id', $actor->id)
            ->first();
        $enrollment = $student?->enrollments()
            ->with(['section', 'academicYear', 'semester'])
            ->whereIn('status', ['enrolled', 'completed'])
            ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
            ->whereHas('semester', fn ($query) => $query->where('status', 'active'))
            ->latest('id')
            ->first();
        $enrollment ??= $student?->enrollments()
            ->with(['section', 'academicYear', 'semester'])
            ->whereIn('status', ['enrolled', 'completed'])
            ->latest('id')
            ->first();
        $profile = $student?->userProfile ?? $actor->profile;

        return $this->ok([
            'identity' => [
                'name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->middle_name, $profile?->last_name]))),
                'student_number' => $student?->student_number,
                'program' => $student?->course?->course_name,
                'program_code' => $student?->course?->course_code,
                'year_level' => $enrollment?->section?->year_level ?? $student?->year_level,
                'section' => $enrollment?->section?->section_name,
            ],
            'summary' => [
                'enrollment_status' => $enrollment?->status,
                'academic_term' => $enrollment ? trim(($enrollment->academicYear?->school_year ?? '').' · '.($enrollment->semester?->semester_name ?? ''), ' ·') : null,
                'enrolled_subjects' => $enrollment?->enrollmentSubjects()->whereIn('subject_status', ['enrolled', 'completed'])->count() ?? 0,
                'unread_monitoring_notices' => $student?->riskNotifications()->whereNull('read_at')->count() ?? 0,
                'upcoming_events' => Event::query()->where('status', 'published')->whereNull('parent_event_id')->where('ends_at', '>', now())->count(),
            ],
            'has_finalized_enrollment' => $enrollment !== null,
        ]);
    }

    public function professor(Request $request, GradingService $grading, EarlyWarningService $warnings): JsonResponse
    {
        $actor = $request->user()->loadMissing(['role', 'profile']);
        $professor = $grading->professor($actor);
        $assignmentIds = SectionSubject::query()
            ->where('professor_id', $professor->id)
            ->whereHas('section.academicYear', fn ($query) => $query->where('status', 'active'))
            ->whereHas('section.semester', fn ($query) => $query->where('status', 'active'))
            ->pluck('section_subjects.id');

        $studentCount = $assignmentIds->isEmpty() ? 0 : EnrollmentSubject::query()
            ->from('enrollment_subjects as es')
            ->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
            ->join('section_subjects as ss', function ($join): void {
                $join->on('ss.section_id', '=', 'e.section_id')
                    ->on('ss.subject_id', '=', 'es.subject_id');
            })
            ->whereIn('ss.id', $assignmentIds)
            ->where('ss.professor_id', $professor->id)
            ->where('es.professor_id', $professor->id)
            ->whereIn('e.status', ['enrolled', 'completed'])
            ->whereIn('es.subject_status', ['enrolled', 'completed'])
            ->distinct('e.student_id')
            ->count('e.student_id');
        $ownedSheets = GradeSheet::query()
            ->where('professor_id', $professor->id)
            ->whereIn('section_subject_id', $assignmentIds);
        $alerts = $warnings->adviserAlerts($actor->id)['summary'];
        $profile = $actor->profile;

        return $this->ok([
            'identity' => [
                'name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->middle_name, $profile?->last_name]))),
                'faculty_id' => $professor->employee_number,
                'department' => Department::query()->whereKey($professor->department_id)->value('department_name'),
            ],
            'summary' => [
                'assigned_classes' => $assignmentIds->count(),
                'students' => $studentCount,
                'grade_sheets_needing_work' => (clone $ownedSheets)->whereIn('status', ['draft', 'returned'])->count(),
                'submitted_grade_sheets' => (clone $ownedSheets)->where('status', 'submitted')->count(),
                'monitoring_alerts' => (int) ($alerts['total'] ?? 0),
                'high_risk_alerts' => (int) ($alerts['urgent'] ?? 0),
                'attention_alerts' => (int) ($alerts['attention'] ?? 0),
            ],
            'has_assignments' => $assignmentIds->isNotEmpty(),
        ]);
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }
}

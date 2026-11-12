<?php

namespace App\Services\StudentRecords;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StudentAdmissionLinkResolver
{
    public const LEGACY_CONVERSION = 'legacy_conversion';

    public const ENROLLMENT_FINALIZATION = 'enrollment_finalization';

    /**
     * @return array{
     *     link: array{applicant: AdmissionApplicant, provenance: string, linked_at: Carbon|null}|null,
     *     ambiguous: bool
     * }
     */
    public function resolution(Student $student): array
    {
        $candidates = AdmissionApplicant::query()
            ->with('cycle.academicYear')
            ->where('user_id', $student->user_id)
            ->where(function (Builder $links) use ($student): void {
                $links->where(function (Builder $legacy) use ($student): void {
                    $legacy->where('status', AdmissionApplicant::CONVERTED)
                        ->where('converted_student_id', $student->id)
                        ->whereNotNull('converted_at');
                })->orWhere(function (Builder $current) use ($student): void {
                    $current->where('status', AdmissionApplicant::ACCEPTED)
                        ->whereNotNull('accepted_at')
                        ->whereExists($this->currentHandoffExistsFor($student));
                });
            })
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            return ['link' => null, 'ambiguous' => $candidates->count() > 1];
        }

        $applicant = $candidates->first();
        if ($applicant->status === AdmissionApplicant::CONVERTED) {
            return ['link' => [
                'applicant' => $applicant,
                'provenance' => self::LEGACY_CONVERSION,
                'linked_at' => $applicant->converted_at,
            ], 'ambiguous' => false];
        }

        $linkedAt = DB::table('enrollment_applications as admission_handoffs')
            ->join('enrollments as admission_enrollments', 'admission_enrollments.id', '=', 'admission_handoffs.enrollment_id')
            ->where('admission_handoffs.admission_applicant_id', $applicant->id)
            ->where('admission_handoffs.student_id', $student->id)
            ->where('admission_handoffs.owner_user_id', $student->user_id)
            ->where('admission_handoffs.status', 'enrolled')
            ->whereNotNull('admission_handoffs.finalized_at')
            ->where('admission_enrollments.student_id', $student->id)
            ->whereColumn('admission_handoffs.academic_year_id', 'admission_enrollments.academic_year_id')
            ->whereColumn('admission_handoffs.semester_id', 'admission_enrollments.semester_id')
            ->where('admission_handoffs.course_id', $applicant->accepted_course_id)
            ->where('admission_handoffs.curriculum_id', $applicant->accepted_curriculum_id)
            ->min('admission_handoffs.finalized_at');

        return ['link' => [
            'applicant' => $applicant,
            'provenance' => self::ENROLLMENT_FINALIZATION,
            'linked_at' => $linkedAt ? Carbon::parse($linkedAt) : null,
        ], 'ambiguous' => false];
    }

    /**
     * Correlated subquery used by the Student Records list. A link is exposed only
     * when exactly one authoritative Admission applicant belongs to the Student.
     */
    public function availabilitySubquery(): Builder
    {
        return AdmissionApplicant::query()
            ->selectRaw('CASE WHEN COUNT(*) = 1 THEN 1 ELSE 0 END')
            ->whereColumn('admission_applicants.user_id', 'students.user_id')
            ->where(function (Builder $links): void {
                $links->where(function (Builder $legacy): void {
                    $legacy->where('admission_applicants.status', AdmissionApplicant::CONVERTED)
                        ->whereNotNull('admission_applicants.converted_at')
                        ->whereColumn('admission_applicants.converted_student_id', 'students.id');
                })->orWhere(function (Builder $current): void {
                    $current->where('admission_applicants.status', AdmissionApplicant::ACCEPTED)
                        ->whereNotNull('admission_applicants.accepted_at')
                        ->whereExists($this->correlatedCurrentHandoffExists());
                });
            });
    }

    private function currentHandoffExistsFor(Student $student): \Closure
    {
        return fn (QueryBuilder $handoff): QueryBuilder => $handoff
            ->selectRaw('1')
            ->from('enrollment_applications as admission_handoffs')
            ->join('enrollments as admission_enrollments', 'admission_enrollments.id', '=', 'admission_handoffs.enrollment_id')
            ->whereColumn('admission_handoffs.admission_applicant_id', 'admission_applicants.id')
            ->where('admission_handoffs.student_id', $student->id)
            ->where('admission_handoffs.owner_user_id', $student->user_id)
            ->where('admission_handoffs.status', 'enrolled')
            ->whereNotNull('admission_handoffs.enrollment_id')
            ->whereNotNull('admission_handoffs.finalized_at')
            ->where('admission_enrollments.student_id', $student->id)
            ->whereColumn('admission_handoffs.academic_year_id', 'admission_enrollments.academic_year_id')
            ->whereColumn('admission_handoffs.semester_id', 'admission_enrollments.semester_id')
            ->whereColumn('admission_handoffs.course_id', 'admission_applicants.accepted_course_id')
            ->whereColumn('admission_handoffs.curriculum_id', 'admission_applicants.accepted_curriculum_id');
    }

    private function correlatedCurrentHandoffExists(): \Closure
    {
        return fn (QueryBuilder $handoff): QueryBuilder => $handoff
            ->selectRaw('1')
            ->from('enrollment_applications as admission_handoffs')
            ->join('enrollments as admission_enrollments', 'admission_enrollments.id', '=', 'admission_handoffs.enrollment_id')
            ->whereColumn('admission_handoffs.admission_applicant_id', 'admission_applicants.id')
            ->whereColumn('admission_handoffs.student_id', 'students.id')
            ->whereColumn('admission_handoffs.owner_user_id', 'students.user_id')
            ->where('admission_handoffs.status', 'enrolled')
            ->whereNotNull('admission_handoffs.enrollment_id')
            ->whereNotNull('admission_handoffs.finalized_at')
            ->whereColumn('admission_enrollments.student_id', 'students.id')
            ->whereColumn('admission_handoffs.academic_year_id', 'admission_enrollments.academic_year_id')
            ->whereColumn('admission_handoffs.semester_id', 'admission_enrollments.semester_id')
            ->whereColumn('admission_handoffs.course_id', 'admission_applicants.accepted_course_id')
            ->whereColumn('admission_handoffs.curriculum_id', 'admission_applicants.accepted_curriculum_id');
    }
}

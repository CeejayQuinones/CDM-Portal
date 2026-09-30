<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentApplication;
use App\Models\EnrollmentSubject;
use App\Models\Subject;
use Illuminate\Support\Collection;

class EnrollmentSubjectPolicy
{
    public function candidates(EnrollmentApplication $a, bool $lock = false): Collection
    {
        return Subject::where('curriculum_id', $a->curriculum_id)->where('semester_id', $a->semester_id)->where('status', 'active')->orderBy('subject_code')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function completed(EnrollmentApplication $a, bool $lock = false): array
    {
        return EnrollmentSubject::join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')->where('enrollments.student_id', $a->student_id)->whereIn('enrollments.status', ['enrolled', 'completed'])->where('enrollment_subjects.subject_status', 'completed')->where('enrollment_subjects.remarks', 'Passed')->when($lock, fn ($q) => $q->lockForUpdate())->pluck('enrollment_subjects.subject_id')->all();
    }

    public function proposal(EnrollmentApplication $a): array
    {
        $done = $this->completed($a);
        $candidates = $this->candidates($a);

        return ['candidates' => $candidates->map(fn ($s) => $s->toArray() + ['completed' => in_array($s->id, $done), 'prerequisite_met' => ! $s->prerequisite_subject_id || in_array($s->prerequisite_subject_id, $done)])->all(),
            'standard_subject_ids' => $candidates->where('year_level', $a->year_level)->pluck('id')->all(), 'selected_subject_ids' => $a->subjects()->pluck('subjects.id')->all()];
    }

    public function validate(EnrollmentApplication $a, array $ids, bool $lock = true): Collection
    {
        abort_unless(count($ids) > 0 && count($ids) === count(array_unique($ids)), 422, 'Choose a nonempty load without duplicate subjects.');
        $candidates = $this->candidates($a, $lock);
        $subjects = $candidates->whereIn('id', $ids);
        $done = $this->completed($a, $lock);
        abort_unless($subjects->count() === count($ids), 422, 'Every subject must be active in the Student curriculum and semester.');
        if ($a->classification === 'regular') {
            $standard = $candidates->where('year_level', $a->year_level)->pluck('id')->sort()->values()->all();
            $selected = collect($ids)->sort()->values()->all();
            abort_unless($standard === $selected, 422, 'Regular enrollment must use the complete standard curriculum load.');
        }
        foreach ($subjects as $s) {
            abort_if(in_array($s->id, $done), 422, 'A completed subject cannot be enrolled again.');
            abort_unless(! $s->prerequisite_subject_id || in_array($s->prerequisite_subject_id, $done), 422, 'A required prerequisite has no recorded passing completion.');
            abort_unless((float) $s->units >= 0, 422, 'A subject has an invalid unit configuration.');
        }

        return $subjects->values();
    }
}

<?php

namespace App\Services\StudentRecords;

use App\Models\AcademicYear;
use App\Models\EnrollmentSubject;
use App\Models\HistoricalGradeEvent;
use App\Models\HistoricalGradeRecord;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HistoricalGradeService
{
    public const FIELDS = ['academic_year', 'semester', 'subject_code', 'final_grade', 'official_result', 'credited_units', 'source_reference'];

    public function options(): array
    {
        return [
            'academic_years' => AcademicYear::orderByDesc('start_date')->get(['id', 'school_year']),
            'semesters' => Semester::orderBy('semester_order')->get(['id', 'semester_name']),
        ];
    }

    public function forStudent(Student $student, bool $officialOnly = false): array
    {
        return HistoricalGradeRecord::query()
            ->where('student_id', $student->id)
            ->when($officialOnly, fn ($query) => $query->where('verification_status', 'verified'))
            ->with(['academicYear:id,school_year', 'semester:id,semester_name', 'enteredBy:id,username', 'events.actorUser:id,username'])
            ->orderByDesc('academic_year_id')->orderByDesc('semester_id')->orderBy('subject_code')
            ->get()->map(fn ($record) => [
                ...$record->only(['id', 'student_id', 'subject_id', 'subject_code', 'subject_name', 'curriculum_id', 'final_grade', 'official_result', 'credited_units', 'source_type', 'source_reference', 'verification_status', 'verified_at', 'created_at']),
                'academic_year' => $record->academicYear?->school_year,
                'semester' => $record->semester?->semester_name,
                'academic_year_id' => $record->academic_year_id,
                'semester_id' => $record->semester_id,
                'entered_by' => $record->enteredBy?->username,
                'events' => $officialOnly ? [] : $record->events->map(fn ($event) => $event->only(['action', 'before', 'after', 'reason', 'created_at']) + ['actor' => $event->actorUser?->username])->all(),
            ])->all();
    }

    /** Resolve only exact, unambiguous student and curriculum subject identities. */
    public function resolve(array $input, ?Student $student = null, ?int $excludingRecord = null): array
    {
        $data = Validator::make($input, [
            'student_number' => ['required', 'string', 'max:20'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'string', 'max:30'],
            'subject_code' => ['required', 'string', 'max:50'],
            'final_grade' => ['required', 'string', 'max:40', 'not_regex:/^[=+@\-\t\r]/'],
            'official_result' => ['required', 'in:Passed,Failed,Incomplete'],
            'credited_units' => ['required', 'numeric', 'between:0,99.99'],
            'source_reference' => ['required', 'string', 'max:255', 'not_regex:/^[=+@\-\t\r]/'],
        ])->validate();

        $students = $student ? collect([$student]) : Student::where('student_number', $data['student_number'])->limit(2)->get();
        if ($students->count() !== 1 || $students->first()->student_number !== trim($data['student_number'])) {
            throw ValidationException::withMessages(['student_number' => ['Unknown or ambiguous Student Number. Create or reconcile the Student identity separately.']]);
        }
        $student = $students->first();
        if (! $student->curriculum_id) {
            throw ValidationException::withMessages(['student_number' => ['This Student has no verified curriculum assignment.']]);
        }
        $year = AcademicYear::where('school_year', trim($data['academic_year']))->first();
        $semester = Semester::where('semester_name', trim($data['semester']))->first();
        if (! $year || ! $semester || $year->school_year !== trim($data['academic_year']) || $semester->semester_name !== trim($data['semester'])) {
            throw ValidationException::withMessages(['academic_year' => ['Academic year or semester is not configured in CDM Portal.']]);
        }
        $subjects = Subject::where('curriculum_id', $student->curriculum_id)
            ->where('subject_code', trim($data['subject_code']))->limit(2)->get();
        if ($subjects->count() !== 1 || $subjects->first()->subject_code !== trim($data['subject_code'])) {
            throw ValidationException::withMessages(['subject_code' => ['Unknown or ambiguous subject code in this Student curriculum; review official equivalency before import.']]);
        }
        $subject = $subjects->first();
        if (HistoricalGradeRecord::where('student_id', $student->id)->where('academic_year_id', $year->id)
            ->where('semester_id', $semester->id)->where('subject_id', $subject->id)
            ->when($excludingRecord, fn ($query) => $query->whereKeyNot($excludingRecord))->exists()) {
            throw ValidationException::withMessages(['subject_code' => ['A historical grade for this Student, term, and subject already exists. Review or correct that record.']]);
        }
        if (EnrollmentSubject::where('subject_id', $subject->id)->whereHas('enrollment', fn ($query) => $query
            ->where('student_id', $student->id)->where('academic_year_id', $year->id)->where('semester_id', $semester->id))
            ->exists()) {
            throw ValidationException::withMessages(['subject_code' => ['This Student already has a CDM enrollment for the same subject and term.']]);
        }

        return [
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'semester_id' => $semester->id,
            'curriculum_id' => $student->curriculum_id, 'subject_id' => $subject->id,
            'subject_code' => $subject->subject_code, 'subject_name' => $subject->subject_name,
            'credited_units' => $data['credited_units'], 'final_grade' => trim($data['final_grade']),
            'official_result' => $data['official_result'], 'source_reference' => trim($data['source_reference']),
        ];
    }

    public function create(array $input, User $actor, string $sourceType, ?Student $student = null): HistoricalGradeRecord
    {
        return DB::transaction(function () use ($input, $actor, $sourceType, $student): HistoricalGradeRecord {
            if ($student) {
                $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            }
            $resolved = $this->resolve($input, $student);
            Student::whereKey($resolved['student_id'])->lockForUpdate()->firstOrFail();
            $resolved = $this->resolve($input, $student);
            $record = HistoricalGradeRecord::create($resolved + [
                'source_type' => $sourceType, 'verification_status' => 'verified',
                'entered_by' => $actor->id, 'verified_by' => $actor->id, 'verified_at' => now(),
            ]);
            $this->event($record, $actor, 'created', null, $record->toArray(), null);

            return $record;
        });
    }

    public function correct(HistoricalGradeRecord $record, array $input, User $actor): HistoricalGradeRecord
    {
        return DB::transaction(function () use ($record, $input, $actor): HistoricalGradeRecord {
            $locked = HistoricalGradeRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($locked->student_id)->lockForUpdate()->firstOrFail();
            $reason = trim((string) ($input['reason'] ?? ''));
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages(['reason' => ['Provide a correction reason of 10 to 1000 characters.']]);
            }
            $resolved = $this->resolve($input, $student, $locked->id);
            $before = $locked->toArray();
            $status = $input['verification_status'] ?? 'verified';
            if (! in_array($status, ['verified', 'disputed', 'revoked'], true)) {
                throw ValidationException::withMessages(['verification_status' => ['Choose Verified, Disputed, or Revoked.']]);
            }
            $locked->forceFill($resolved + [
                'verification_status' => $status, 'verified_by' => $status === 'verified' ? $actor->id : null,
                'verified_at' => $status === 'verified' ? now() : null,
            ])->save();
            $this->event($locked, $actor, 'corrected', $before, $locked->fresh()->toArray(), $reason);

            return $locked;
        });
    }

    private function event(HistoricalGradeRecord $record, User $actor, string $action, ?array $before, array $after, ?string $reason): void
    {
        HistoricalGradeEvent::create([
            'historical_grade_record_id' => $record->id, 'actor_user_id' => $actor->id,
            'action' => $action, 'before' => $before, 'after' => $after, 'reason' => $reason,
        ]);
    }
}

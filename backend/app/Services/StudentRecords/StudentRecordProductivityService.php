<?php

namespace App\Services\StudentRecords;

use App\Models\Student;
use App\Models\StudentRecordAuditEvent;
use App\Models\StudentRecordRecentView;
use App\Models\StudentRecordSavedFilter;
use App\Models\StudentRecordStaffPreference;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Database\Eloquent\Builder;

class StudentRecordProductivityService
{
    public const DEFAULT_COLUMNS = ['student_number', 'name', 'program', 'year_level', 'section', 'student_status', 'physical_record_status'];

    public function __construct(
        private readonly StudentService $students,
        private readonly StudentRecordCompletenessService $completeness,
    ) {}

    public function recordView(User $user, Student $student): void
    {
        StudentRecordRecentView::query()->updateOrCreate(
            ['user_id' => $user->id, 'student_id' => $student->id],
            ['viewed_at' => now()],
        );
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user): array
    {
        $recent = StudentRecordRecentView::query()->where('user_id', $user->id)
            ->latest('viewed_at')->limit(15)->get();
        $recentStudents = $this->students->withCurrentEnrollment(Student::query()->whereKey($recent->pluck('student_id')))
            ->with(['userProfile:id,first_name,middle_name,last_name,suffix', 'course:id,course_code', 'physicalRecordLocation:id,student_id'])
            ->get()->keyBy('id');

        $attentionQuery = $this->students->withCurrentEnrollment($this->attentionQuery())->with([
            'userProfile:id,first_name,middle_name,last_name,suffix,birth_date,email,contact_number,address',
            'course:id,course_code', 'documents:id,student_id,availability_status,verification_status',
            'physicalRecordLocation:id,student_id',
        ]);
        $attentionTotal = (clone $attentionQuery)->count();
        $attention = $attentionQuery->orderBy('student_number')->limit(12)->get()
            ->map(fn (Student $student): array => $this->compactStudent($student) + ['completeness' => $this->completeness->evaluate($student)]);

        $preference = StudentRecordStaffPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['visible_columns' => self::DEFAULT_COLUMNS],
        );

        return [
            'recent_students' => $recent->map(function (StudentRecordRecentView $view) use ($recentStudents): ?array {
                $student = $recentStudents->get($view->student_id);

                return $student ? $this->compactStudent($student) + ['viewed_at' => $view->viewed_at?->toISOString()] : null;
            })->filter()->values(),
            'saved_filters' => StudentRecordSavedFilter::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'filters']),
            'visible_columns' => $preference->visible_columns ?: self::DEFAULT_COLUMNS,
            'attention' => ['total' => $attentionTotal, 'students' => $attention],
            'today' => $this->today($user),
        ];
    }

    /** @return array<string, int> */
    private function today(User $user): array
    {
        $events = StudentRecordAuditEvent::query()->where('actor_user_id', $user->id)->whereDate('created_at', today());

        return [
            'students_viewed' => StudentRecordRecentView::query()->where('user_id', $user->id)->whereDate('viewed_at', today())->count(),
            'records_updated' => (clone $events)->whereIn('action', ['student_profile_updated', 'student_status_changed'])->count(),
            'physical_records_moved' => (clone $events)->where('action', 'physical_record_location_changed')->count(),
            'documents_updated' => (clone $events)->whereIn('action', ['document_availability_changed', 'document_file_uploaded', 'document_file_replaced', 'document_file_deleted'])->count(),
        ];
    }

    private function attentionQuery(): Builder
    {
        return Student::query()->where(function (Builder $query): void {
            $query->whereDoesntHave('physicalRecordLocation')
                ->orWhereDoesntHave('documents')
                ->orWhereHas('documents', fn (Builder $documents) => $documents
                    ->where('availability_status', 'missing')->orWhere('verification_status', 'pending'))
                ->orWhereHas('userProfile', fn (Builder $profile) => $profile
                    ->whereNull('birth_date')->orWhereNull('email')->orWhereNull('contact_number')->orWhereNull('address'));
        });
    }

    /** @return array<string, mixed> */
    private function compactStudent(Student $student): array
    {
        $enrollment = $student->currentEnrollment;
        $application = $student->currentEnrollmentApplication;

        return [
            'id' => $student->id,
            'student_number' => $student->student_number,
            'name' => trim(collect([$student->userProfile?->first_name, $student->userProfile?->middle_name, $student->userProfile?->last_name, $student->userProfile?->suffix])->filter()->join(' ')),
            'program' => $student->course?->course_code,
            'section' => $application?->section?->section_name ?? $enrollment?->section?->section_name,
        ];
    }
}

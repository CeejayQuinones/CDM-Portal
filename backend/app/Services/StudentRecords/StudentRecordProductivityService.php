<?php

namespace App\Services\StudentRecords;

use App\Models\Student;
use App\Models\StudentDocument;
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
        private readonly StudentRecordRequirementService $requirements,
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

        $requirementAttention = $this->requirementAttention();

        return [
            'recent_students' => $recent->map(function (StudentRecordRecentView $view) use ($recentStudents): ?array {
                $student = $recentStudents->get($view->student_id);

                return $student ? $this->compactStudent($student) + ['viewed_at' => $view->viewed_at?->toISOString()] : null;
            })->filter()->values(),
            'saved_filters' => StudentRecordSavedFilter::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'filters']),
            'visible_columns' => $preference->visible_columns ?: self::DEFAULT_COLUMNS,
            'attention' => [
                'total' => $attentionTotal + $requirementAttention['total'],
                'students' => $attention,
                'requirements_total' => $requirementAttention['total'],
                'items' => $requirementAttention['items'],
                'categories' => [
                    ['value' => 'all', 'label' => 'All'],
                    ['value' => 'digital_missing', 'label' => 'Digital Missing'],
                    ['value' => 'awaiting_review', 'label' => 'Awaiting Review'],
                    ['value' => 'needs_resubmission', 'label' => 'Needs Resubmission'],
                    ['value' => 'hard_copy_required', 'label' => 'Hard Copy Required'],
                    ['value' => 'physical_records', 'label' => 'Physical Records'],
                ],
            ],
            'today' => $this->today($user),
        ];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    private function requirementAttention(): array
    {
        $types = $this->requirements->types();
        if ($types->isEmpty()) {
            return ['total' => 0, 'items' => []];
        }

        $typeIds = $types->pluck('id');
        $total = $types->sum(fn ($type): int => Student::query()
            ->whereDoesntHave('documents', fn (Builder $documents) => $documents->where('document_type_id', $type->id))
            ->count()
            + StudentDocument::query()->where('document_type_id', $type->id)
                ->where(fn (Builder $state) => $state
                    ->where('availability_status', 'missing')
                    ->orWhereIn('verification_status', ['pending', 'rejected'])
                    ->orWhere('physical_copy_status', 'required'))
                ->count());
        $students = Student::query()
            ->where(function (Builder $query) use ($typeIds): void {
                foreach ($typeIds as $typeId) {
                    $query->orWhereDoesntHave('documents', fn (Builder $documents) => $documents->where('document_type_id', $typeId));
                }
                $query->orWhereHas('documents', fn (Builder $documents) => $documents
                    ->whereIn('document_type_id', $typeIds)
                    ->where(fn (Builder $state) => $state
                        ->where('availability_status', 'missing')
                        ->orWhereIn('verification_status', ['pending', 'rejected'])
                        ->orWhere('physical_copy_status', 'required')));
            })
            ->with([
                'userProfile:id,first_name,middle_name,last_name,suffix',
                'documents' => fn ($query) => $query->whereIn('document_type_id', $typeIds),
            ])
            ->orderBy('student_number')
            ->limit(50)
            ->get(['id', 'user_profile_id', 'student_number']);

        $items = $students->flatMap(function (Student $student) use ($types): array {
            $documents = $student->documents->keyBy('document_type_id');

            return $types->map(function ($type) use ($student, $documents): ?array {
                $document = $documents->get($type->id);
                $digital = $this->requirements->digitalStatus($document);
                $physical = $document?->physical_copy_status ?? 'not_required';
                $reasons = collect([
                    $digital === 'missing' ? 'digital_missing' : null,
                    in_array($digital, ['submitted', 'under_review'], true) ? 'awaiting_review' : null,
                    $digital === 'needs_resubmission' ? 'needs_resubmission' : null,
                    $physical === 'required' ? 'hard_copy_required' : null,
                ])->filter()->values();
                if ($reasons->isEmpty()) {
                    return null;
                }

                return [
                    'key' => $student->id.':'.$type->id,
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => trim(collect([$student->userProfile?->first_name, $student->userProfile?->middle_name, $student->userProfile?->last_name, $student->userProfile?->suffix])->filter()->join(' ')),
                    'document_id' => $document?->id,
                    'document_type_id' => $type->id,
                    'document_type' => $type->document_name,
                    'digital_status' => $digital,
                    'physical_status' => $physical,
                    'submitted_date' => $document?->submitted_date?->toDateString(),
                    'hard_copy_required_at' => $document?->physical_copy_required_at?->toISOString(),
                    'attention_reasons' => $reasons->all(),
                    'attention_reason' => $reasons->map(fn (string $reason): string => match ($reason) {
                        'digital_missing' => 'Missing Digital Copy',
                        'awaiting_review' => 'Awaiting Digital Review',
                        'needs_resubmission' => 'Needs Resubmission',
                        default => 'Hard Copy Awaiting Submission',
                    })->join(' · '),
                ];
            })->filter()->all();
        })->values();

        return ['total' => $total, 'items' => $items->take(50)->all()];
    }

    /** @return array<string, int> */
    private function today(User $user): array
    {
        $events = StudentRecordAuditEvent::query()->where('actor_user_id', $user->id)->whereDate('created_at', today());

        return [
            'students_viewed' => StudentRecordRecentView::query()->where('user_id', $user->id)->whereDate('viewed_at', today())->count(),
            'records_updated' => (clone $events)->whereIn('action', ['student_profile_updated', 'student_status_changed'])->count(),
            'physical_records_moved' => (clone $events)->where('action', 'physical_record_location_changed')->count(),
            'documents_updated' => (clone $events)->whereIn('action', [
                'document_availability_changed', 'document_file_uploaded', 'document_file_replaced', 'document_file_deleted',
                'student_document_submitted', 'student_document_resubmitted', 'student_document_verified',
                'student_document_resubmission_requested', 'student_document_hard_copy_required',
                'student_document_hard_copy_received', 'student_document_hard_copy_reopened',
            ])->count(),
        ];
    }

    private function attentionQuery(): Builder
    {
        return Student::query()->where(function (Builder $query): void {
            $query->whereDoesntHave('physicalRecordLocation')
                ->orWhereDoesntHave('documents')
                ->orWhereHas('documents', fn (Builder $documents) => $documents
                    ->where('availability_status', 'missing')
                    ->orWhereIn('verification_status', ['pending', 'rejected'])
                    ->orWhere('physical_copy_status', 'required'))
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

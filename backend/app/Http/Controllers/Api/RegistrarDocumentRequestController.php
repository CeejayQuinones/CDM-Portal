<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Requests\UpdateRegistrarRequest;
use App\Models\Appointment;
use App\Models\DocumentRequest;
use App\Models\RegistrarStaff;
use App\Services\DocumentRequestWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegistrarDocumentRequestController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'approved'];

    private const HISTORY_STATUSES = ['completed', 'rejected', 'cancelled'];

    private const ACTIVE_APPOINTMENT_STATUSES = ['pending', 'confirmed'];

    private const HISTORY_APPOINTMENT_STATUSES = ['cancelled', 'completed', 'no_show'];

    private const TIME_FILTERS = ['all', 'today', 'yesterday', 'last_7_days', 'this_month'];

    private const ACTIVE_APPOINTMENT_GROUPS = ['active', 'upcoming', 'today', 'recent'];

    private const BUSINESS_TIMEZONE = 'Asia/Manila';

    public function __construct(private readonly DocumentRequestWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'view' => ['nullable', 'in:work_queues'],
            'status' => ['nullable', Rule::in(self::ACTIVE_STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'time_filter' => ['nullable', Rule::in(self::TIME_FILTERS)],
            'request_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'pending_page' => ['nullable', 'integer', 'min:1'],
            'approved_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->summaryQuery()
            ->select([
                'id', 'student_id', 'document_type_id', 'quantity', 'total_fee', 'purpose', 'status',
                'request_date', 'release_date', 'remarks', 'approved_at', 'completed_at',
                'rejected_at', 'cancelled_at', 'code_verified_at', 'created_at', 'updated_at',
            ])
            ->latest('updated_at')
            ->latest('id');

        $this->applySearch($query, $request->input('search'));
        $this->applyExactRequestId($query, $request->input('request_id'));
        $this->applyTimeFilter($query, 'updated_at', $request->input('time_filter'));

        if ($request->input('view') === 'work_queues') {
            return $this->ok([
                'pending' => (clone $query)
                    ->where('status', 'pending')
                    ->paginate(20, ['*'], 'pending_page'),
                'approved' => (clone $query)
                    ->where('status', 'approved')
                    ->paginate(10, ['*'], 'approved_page'),
            ], 'Document request work queues retrieved successfully.');
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', self::ACTIVE_STATUSES);
        }

        return $this->ok($query->paginate(20), 'Document request queue retrieved successfully.');
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'request_status' => ['nullable', 'in:completed,cancelled,rejected'],
            'appointment_status' => ['nullable', 'in:cancelled,completed,no_show'],
            'search' => ['nullable', 'string', 'max:100'],
            'time_filter' => ['nullable', Rule::in(self::TIME_FILTERS)],
            'request_id' => ['nullable', 'integer', 'min:1'],
            'appointment_id' => ['nullable', 'integer', 'min:1'],
            'section' => ['nullable', Rule::in(['requests', 'appointments'])],
            'request_page' => ['nullable', 'integer', 'min:1'],
            'appointment_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->summaryQuery()
            ->select([
                'id', 'student_id', 'document_type_id', 'quantity', 'total_fee', 'purpose', 'status',
                'request_date', 'release_date', 'remarks', 'approved_at', 'completed_at',
                'rejected_at', 'cancelled_at', 'code_verified_at', 'created_at', 'updated_at',
            ])
            ->with(['latestAppointment' => fn ($appointments) => $appointments->select([
                'appointments.id',
                'appointments.document_request_id',
                'appointments.appointment_date',
                'appointments.appointment_time',
                'appointments.status',
            ])])
            ->latest('updated_at')
            ->latest('id');

        if ($status = $request->input('request_status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', self::HISTORY_STATUSES);
        }

        $this->applySearch($query, $request->input('search'));
        $this->applyExactRequestId($query, $request->input('request_id'));
        $this->applyTimeFilter($query, 'updated_at', $request->input('time_filter'));

        if ($request->filled('appointment_id')) {
            $query->whereHas('appointments', fn (Builder $appointmentQuery) => $appointmentQuery->whereKey($request->integer('appointment_id')));
        }

        $appointments = Appointment::query()
            ->select(['id', 'student_id', 'document_request_id', 'appointment_date', 'appointment_time', 'status', 'remarks', 'created_at', 'updated_at'])
            ->whereNotNull('document_request_id')
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'documentRequest:id,document_type_id,status,request_date,remarks,created_at,updated_at,released_at,rejected_at',
                'documentRequest.documentType:id,document_name',
            ])
            ->latest('updated_at')
            ->latest('id');

        if ($appointmentStatus = $request->input('appointment_status')) {
            $appointments->where('status', $appointmentStatus);
        } else {
            $appointments->whereIn('status', self::HISTORY_APPOINTMENT_STATUSES);
        }

        $this->applyAppointmentSearch($appointments, $request->input('search'));
        $this->applyExactAppointmentFilters($appointments, $request->input('request_id'), $request->input('appointment_id'));
        $this->applyTimeFilter($appointments, 'updated_at', $request->input('time_filter'));

        $section = $request->input('section');

        return $this->ok([
            'requests' => $section === 'appointments' ? null : $query->paginate(20, ['*'], 'request_page'),
            'appointments' => $section === 'requests' ? null : $appointments->paginate(20, ['*'], 'appointment_page'),
        ], 'Document request history retrieved successfully.');
    }

    public function show(DocumentRequest $documentRequest): JsonResponse
    {
        return $this->ok($documentRequest->load($this->detailRelations()), 'Document request retrieved successfully.');
    }

    public function update(UpdateRegistrarRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $staff = $this->staffFor($request);
        $action = $request->string('action')->toString();
        $reason = $request->filled('reason') ? trim($request->string('reason')->toString()) : null;
        if ($action === 'approve') {
            $result = $this->workflow->approve($documentRequest, $staff);

            return $this->ok($result['request'], $result['email_delivered'] ? 'Document request approved and notification sent.' : 'Document request approved; email delivery was unavailable.');
        }
        $updated = $action === 'reject'
            ? $this->workflow->reject($documentRequest, $staff, (string) $reason)
            : $this->workflow->finalize($documentRequest, $staff, $action, $reason);

        return $this->ok($updated, 'Document request updated successfully.');
    }

    public function assignAppointment(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $validated = $request->validate(['appointment_date' => ['required', 'date_format:Y-m-d']]);

        return $this->ok($this->workflow->assignDate($documentRequest, $this->staffFor($request), $validated['appointment_date']), 'Appointment date assigned; request remains pending.');
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate(['verification_code' => ['required', 'digits:6']]);

        return $this->ok($this->workflow->verify($validated['verification_code'], $this->staffFor($request)), 'Verification code accepted.');
    }

    public function resendClaimCode(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $result = $this->workflow->resendClaimCode($documentRequest, $this->staffFor($request));
        if (! $result['email_delivered']) {
            return response()->json(['success' => false, 'message' => 'A new claim code was generated, but the email could not be delivered. Retry resend after checking mail configuration.'], 503);
        }

        return $this->ok($result['request'], 'A new claim code was sent to the student.');
    }

    public function appointments(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,confirmed'],
            'group' => ['nullable', Rule::in(self::ACTIVE_APPOINTMENT_GROUPS)],
            'search' => ['nullable', 'string', 'max:100'],
            'time_filter' => ['nullable', Rule::in(self::TIME_FILTERS)],
            'request_id' => ['nullable', 'integer', 'min:1'],
            'appointment_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Appointment::query()
            ->select(['id', 'student_id', 'document_request_id', 'appointment_date', 'appointment_time', 'status', 'remarks', 'created_at', 'updated_at'])
            ->whereNotNull('document_request_id')
            ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES)
            ->whereHas('documentRequest', fn ($requests) => $requests->where('status', 'approved'))
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'documentRequest:id,document_type_id,status,request_date,code_verified_at,created_at,updated_at',
                'documentRequest.documentType:id,document_name',
            ])
            ->latest('updated_at')
            ->latest('id');

        $businessToday = Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();
        $businessTomorrow = $businessToday->copy()->addDay()->toDateString();
        if ($request->input('group') === 'upcoming') {
            $query->where('appointment_date', '>=', $businessTomorrow);
        } elseif (! $request->filled('group') || $request->input('group') === 'today') {
            $query->where('appointment_date', '>=', $businessToday->toDateString())
                ->where('appointment_date', '<', $businessTomorrow);
        } elseif ($request->input('group') === 'recent') {
            $this->applyTimeFilter($query, 'updated_at', 'last_7_days');
        }

        if ($request->filled('date')) {
            $date = Carbon::parse($request->input('date'), self::BUSINESS_TIMEZONE);
            $query->where('appointment_date', '>=', $date->toDateString())
                ->where('appointment_date', '<', $date->copy()->addDay()->toDateString());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $this->applyAppointmentSearch($query, $request->input('search'));
        $this->applyExactAppointmentFilters($query, $request->input('request_id'), $request->input('appointment_id'));
        $this->applyTimeFilter($query, 'appointment_date', $request->input('time_filter'), true);

        $items = $request->has('page') ? $query->paginate(50) : $query->limit(50)->get();

        return $this->ok($items, 'Appointments retrieved successfully.');
    }

    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'time_filter' => ['nullable', Rule::in(self::TIME_FILTERS)],
        ]);
        $limit = (int) ($validated['limit'] ?? 8);
        $timeFilter = $validated['time_filter'] ?? 'all';
        $candidateLimit = max($limit * 4, 40);

        $requests = DocumentRequest::query()
            ->select([
                'id', 'student_id', 'document_type_id', 'status', 'created_at', 'updated_at',
                'approved_at', 'completed_at', 'rejected_at', 'cancelled_at',
            ])
            ->with([
                ...$this->activityStudentRelations(),
                'documentType:id,document_name',
                'statusChanges:id,document_request_id,action,reason,created_at',
            ])
            ->when($timeFilter !== 'all', fn (Builder $query) => $this->applyAnyTimestampFilter($query, [
                'created_at', 'updated_at', 'approved_at', 'completed_at', 'rejected_at', 'cancelled_at',
            ], $timeFilter))
            ->latest('updated_at')
            ->latest('id')
            ->limit($candidateLimit)
            ->get();

        $appointments = Appointment::query()
            ->select(['id', 'student_id', 'document_request_id', 'status', 'created_at', 'updated_at'])
            ->whereNotNull('document_request_id')
            ->with([
                ...$this->activityStudentRelations(),
                'documentRequest:id,document_type_id,status',
                'documentRequest.documentType:id,document_name',
            ])
            ->when($timeFilter !== 'all', fn (Builder $query) => $this->applyAnyTimestampFilter($query, ['created_at', 'updated_at'], $timeFilter))
            ->latest('updated_at')
            ->latest('id')
            ->limit($candidateLimit)
            ->get();

        $items = collect()
            ->concat($requests->flatMap(fn (DocumentRequest $documentRequest) => $this->requestActivities($documentRequest)))
            ->concat($appointments->flatMap(fn (Appointment $appointment) => $this->appointmentActivities($appointment)))
            ->filter(fn (array $item) => $this->timestampMatchesFilter($item['occurred_at'], $timeFilter))
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values();

        return $this->ok($items, 'Recent document request activity retrieved successfully.');
    }

    public function updateAppointment(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        abort_if(
            $appointment->document_request_id !== null
                && ($request->filled('status') || $request->filled('appointment_date') || $request->filled('appointment_time')),
            422,
            'Use the document request workflow to change a linked appointment.',
        );
        $staff = $this->staffFor($request);
        $updates = $request->validated();
        $updates['registrar_staff_id'] = $staff->id;
        $status = $updates['status'] ?? $appointment->status;
        if ($request->filled('status') && $status !== $appointment->status) {
            $allowedTransitions = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['completed', 'cancelled', 'no_show'],
                'completed' => [],
                'cancelled' => [],
                'no_show' => [],
            ];
            abort_unless(
                in_array($status, $allowedTransitions[$appointment->status] ?? [], true),
                422,
                'This action is not valid for the appointment status.',
            );
        }
        $date = $updates['appointment_date'] ?? $appointment->appointment_date->toDateString();
        $time = $updates['appointment_time'] ?? substr((string) $appointment->appointment_time, 0, 5);
        if (in_array($status, ['pending', 'confirmed'], true)) {
            $updates['active_slot_key'] = $date.' '.$time;
        } else {
            $updates['active_slot_key'] = null;
        }
        if ($status === 'completed') {
            $updates['completed_at'] = now();
        } elseif ($status === 'cancelled') {
            $updates['cancelled_at'] = now();
        }
        try {
            DB::transaction(function () use ($appointment, $updates) {
                $appointment->update($updates);
            });
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'That appointment slot is unavailable.'], 409);
        }

        return $this->ok($appointment->fresh()->load([
            'student:id,user_id,student_number',
            'student.user:id',
            'student.user.profile:id,user_id,first_name,last_name',
            'documentRequest:id,document_type_id,status',
            'documentRequest.documentType:id,document_name',
        ]), 'Appointment updated successfully.');
    }

    private function staffFor(Request $request): RegistrarStaff
    {
        $staff = $request->user()->registrarStaff;
        abort_unless($staff instanceof RegistrarStaff, 403, 'A registrar staff profile is required for this feature.');

        return $staff;
    }

    private function requireStatus(DocumentRequest $request, array $allowed): void
    {
        abort_unless(in_array($request->status, $allowed, true), 422, 'This action is not valid for the request status.');
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function summaryQuery(): Builder
    {
        return DocumentRequest::query()->with([
            'student:id,user_id,student_number',
            'student.user:id',
            'student.user.profile:id,user_id,first_name,last_name',
            'documentType:id,document_name',
        ]);
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }
        $requestId = $this->requestIdFromSearch($search);

        $query->where(function (Builder $builder) use ($requestId, $search): void {
            $builder->whereHas('student', fn (Builder $students) => $students->where('student_number', 'like', "%{$search}%"))
                ->orWhereHas('student.user.profile', fn (Builder $profiles) => $this->applyProfileNameSearch($profiles, $search))
                ->orWhereHas('documentType', fn (Builder $types) => $types->where('document_name', 'like', "%{$search}%"));

            if ($requestId !== null) {
                $builder->orWhere($builder->getModel()->getQualifiedKeyName(), $requestId);
            }
        });
    }

    private function applyAppointmentSearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }
        $requestId = $this->requestIdFromSearch($search);

        $query->where(function (Builder $builder) use ($requestId, $search): void {
            $builder->whereHas('student', fn (Builder $students) => $students->where('student_number', 'like', "%{$search}%"))
                ->orWhereHas('student.user.profile', fn (Builder $profiles) => $this->applyProfileNameSearch($profiles, $search))
                ->orWhereHas('documentRequest.documentType', fn (Builder $types) => $types->where('document_name', 'like', "%{$search}%"));

            if ($requestId !== null) {
                $builder->orWhereHas('documentRequest', fn (Builder $requests) => $requests->whereKey($requestId));
            }
        });
    }

    private function applyProfileNameSearch(Builder $query, string $search): void
    {
        $terms = preg_split('/\s+/', trim($search)) ?: [];

        foreach ($terms as $term) {
            $query->where(function (Builder $names) use ($term): void {
                $names->where('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%");
            });
        }
    }

    private function requestIdFromSearch(string $search): ?int
    {
        if (! preg_match('/^(?:REQ-)?0*([1-9][0-9]*)$/i', trim($search), $matches)) {
            return null;
        }

        $requestId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $requestId === false ? null : $requestId;
    }

    private function applyExactRequestId(Builder $query, mixed $requestId): void
    {
        if ($requestId !== null && $requestId !== '') {
            $query->whereKey((int) $requestId);
        }
    }

    private function applyExactAppointmentFilters(Builder $query, mixed $requestId, mixed $appointmentId): void
    {
        if ($requestId !== null && $requestId !== '') {
            $query->where('document_request_id', (int) $requestId);
        }
        if ($appointmentId !== null && $appointmentId !== '') {
            $query->whereKey((int) $appointmentId);
        }
    }

    private function applyTimeFilter(Builder $query, string $column, ?string $filter, bool $dateOnly = false): void
    {
        $bounds = $this->timeBounds($filter, $dateOnly);
        if ($bounds === null) {
            return;
        }

        [$start, $end] = $bounds;
        if ($dateOnly) {
            $query->where($column, '>=', $start->toDateString())
                ->where($column, '<', $end->copy()->addDay()->toDateString());

            return;
        }

        $query->whereBetween($column, [$start, $end]);
    }

    private function applyAnyTimestampFilter(Builder $query, array $columns, string $filter): void
    {
        $bounds = $this->timeBounds($filter);
        if ($bounds === null) {
            return;
        }

        $query->where(function (Builder $timestamps) use ($bounds, $columns): void {
            foreach ($columns as $column) {
                $timestamps->orWhereBetween($column, $bounds);
            }
        });
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function timeBounds(?string $filter, bool $dateOnly = false): ?array
    {
        $now = Carbon::now(self::BUSINESS_TIMEZONE);

        $bounds = match ($filter ?: 'all') {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => null,
        };

        if ($bounds === null || $dateOnly) {
            return $bounds;
        }

        $storageTimezone = (string) (DB::connection()->getConfig('timezone') ?: config('app.timezone', 'UTC'));

        return [
            $bounds[0]->setTimezone($storageTimezone),
            $bounds[1]->setTimezone($storageTimezone),
        ];
    }

    private function timestampMatchesFilter(string $timestamp, string $filter): bool
    {
        $bounds = $this->timeBounds($filter);
        if ($bounds === null) {
            return true;
        }

        $occurredAt = Carbon::parse($timestamp);

        return $occurredAt->greaterThanOrEqualTo($bounds[0]) && $occurredAt->lessThanOrEqualTo($bounds[1]);
    }

    private function requestActivities(DocumentRequest $documentRequest): array
    {
        $events = [['submitted', $documentRequest->created_at, 'submitted', null]];
        $loggedActions = [];

        foreach ($documentRequest->statusChanges as $statusChange) {
            $events[] = [
                $statusChange->action,
                $statusChange->created_at,
                "status-change-{$statusChange->id}",
                $statusChange->reason,
            ];
            $loggedActions[] = $statusChange->action;
        }

        $workflowEvents = [
            'approved' => $documentRequest->approved_at,
            'completed' => $documentRequest->completed_at,
            'rejected' => $documentRequest->rejected_at,
        ];

        foreach ($workflowEvents as $action => $occurredAt) {
            if ($occurredAt !== null && ! in_array($action, $loggedActions, true)) {
                $events[] = [$action, $occurredAt, $action, null];
            }
        }
        if ($documentRequest->status === 'cancelled' && ! in_array('cancelled', $loggedActions, true)) {
            $events[] = ['cancelled', $documentRequest->updated_at, 'cancelled', null];
        }

        return array_map(
            fn (array $event) => $this->activityItem(
                'request',
                $event[0],
                $event[1],
                $documentRequest,
                $event[2],
                $event[3],
            ),
            $events,
        );
    }

    private function appointmentActivities(Appointment $appointment): array
    {
        $events = [['scheduled', $appointment->created_at]];
        if ($appointment->status !== 'pending' && $appointment->updated_at !== null) {
            $events[] = [$appointment->status, $appointment->updated_at];
        }

        return array_map(
            fn (array $event) => $this->activityItem('appointment', $event[0], $event[1], $appointment),
            $events,
        );
    }

    private function activityItem(
        string $type,
        string $action,
        mixed $occurredAt,
        DocumentRequest|Appointment $record,
        ?string $eventId = null,
        ?string $reason = null,
    ): array {
        $documentRequest = $record instanceof DocumentRequest ? $record : $record->documentRequest;
        $documentType = $record instanceof DocumentRequest ? $record->documentType : $record->documentRequest?->documentType;
        $profile = $record->student?->user?->profile;
        $historical = $record instanceof DocumentRequest
            ? in_array($record->status, self::HISTORY_STATUSES, true)
            : in_array($record->status, self::HISTORY_APPOINTMENT_STATUSES, true);
        $destination = match (true) {
            $historical => 'history',
            $record instanceof Appointment, $documentRequest?->status === 'approved' => 'appointments',
            default => 'requests',
        };

        return [
            'id' => "{$type}:{$record->getKey()}:".($eventId ?? $action),
            'type' => $type,
            'action' => $action,
            'occurred_at' => Carbon::parse($occurredAt)->toISOString(),
            'request_id' => $documentRequest?->id,
            'request_reference' => $documentRequest === null ? null : sprintf('REQ-%06d', $documentRequest->id),
            'appointment_id' => $record instanceof Appointment ? $record->id : null,
            'reason' => $reason,
            'destination' => $destination,
            'student' => [
                'id' => $record->student?->id,
                'student_number' => $record->student?->student_number,
                'user' => [
                    'profile' => [
                        'first_name' => $profile?->first_name,
                        'last_name' => $profile?->last_name,
                    ],
                ],
            ],
            'document_type' => [
                'id' => $documentType?->id,
                'document_name' => $documentType?->document_name,
            ],
        ];
    }

    private function activityStudentRelations(): array
    {
        return [
            'student:id,user_id,student_number',
            'student.user:id',
            'student.user.profile:id,user_id,first_name,last_name',
        ];
    }

    private function detailRelations(): array
    {
        return [
            'student:id,user_id,user_profile_id,course_id,student_number,year_level,student_status',
            'student.userProfile:id,first_name,middle_name,last_name,suffix,profile_photo',
            'student.course:id,course_code,course_name',
            'student.documents:id,student_id,document_type_id,availability_status,verification_status,remarks,submitted_date',
            'student.documents.documentType:id,document_name',
            'student.physicalRecordLocation.cabinetSlot.cabinet:id,cabinet_code,description,rows,columns',
            'documentType:id,document_name,requires_appointment',
            'appointments:id,document_request_id,registrar_staff_id,appointment_date,appointment_time,status,remarks,completed_at,cancelled_at,updated_at',
            'statusChanges:id,document_request_id,registrar_staff_id,actor_type,from_status,to_status,action,reason,created_at',
            'statusChanges.registrarStaff:id,user_id,employee_number',
            'statusChanges.registrarStaff.user:id',
            'statusChanges.registrarStaff.user.profile:id,user_id,first_name,last_name',
        ];
    }
}

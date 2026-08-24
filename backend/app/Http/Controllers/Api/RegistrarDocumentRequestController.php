<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Requests\UpdateRegistrarRequest;
use App\Models\Appointment;
use App\Models\DocumentRequest;
use App\Models\RegistrarStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrarDocumentRequestController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'processing', 'ready_for_release'];

    private const HISTORY_STATUSES = ['released', 'rejected', 'cancelled'];

    private const ACTIVE_APPOINTMENT_STATUSES = ['pending', 'confirmed'];

    private const HISTORY_APPOINTMENT_STATUSES = ['cancelled', 'completed', 'no_show'];

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,processing,ready_for_release,released,cancelled,rejected'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = $this->summaryQuery()
            ->select(['id', 'student_id', 'document_type_id', 'quantity', 'total_fee', 'status', 'request_date', 'created_at'])
            ->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', self::ACTIVE_STATUSES);
        }

        $this->applySearch($query, $request->input('search'));

        return $this->ok($query->paginate(20), 'Document request queue retrieved successfully.');
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'request_status' => ['nullable', 'in:released,cancelled,rejected'],
            'appointment_status' => ['nullable', 'in:cancelled,completed,no_show'],
            'search' => ['nullable', 'string', 'max:100'],
            'request_page' => ['nullable', 'integer', 'min:1'],
            'appointment_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->summaryQuery()
            ->select([
                'id', 'student_id', 'document_type_id', 'status', 'request_date',
                'release_date', 'released_at', 'rejected_at', 'remarks', 'updated_at',
            ])
            ->with(['latestAppointment' => fn ($appointments) => $appointments->select([
                'appointments.id',
                'appointments.document_request_id',
                'appointments.appointment_date',
                'appointments.appointment_time',
                'appointments.status',
            ])])
            ->latest();

        if ($status = $request->input('request_status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', self::HISTORY_STATUSES);
        }

        $this->applySearch($query, $request->input('search'));

        $appointments = Appointment::query()
            ->select(['id', 'student_id', 'document_request_id', 'appointment_date', 'appointment_time', 'status', 'remarks', 'updated_at'])
            ->whereNotNull('document_request_id')
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'documentRequest:id,document_type_id,status,remarks',
                'documentRequest.documentType:id,document_name',
            ])
            ->latest('updated_at');

        if ($appointmentStatus = $request->input('appointment_status')) {
            $appointments->where('status', $appointmentStatus);
        } else {
            $appointments->whereIn('status', self::HISTORY_APPOINTMENT_STATUSES);
        }

        $this->applyAppointmentSearch($appointments, $request->input('search'));

        return $this->ok([
            'requests' => $query->paginate(20, ['*'], 'request_page'),
            'appointments' => $appointments->paginate(20, ['*'], 'appointment_page'),
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
        $updates = ['registrar_staff_id' => $staff->id];
        if ($request->has('remarks')) {
            $updates['remarks'] = $request->input('remarks');
        }

        switch ($action) {
            case 'approve':
                $this->requireStatus($documentRequest, ['pending']);
                $updates += ['status' => 'processing', 'approved_at' => now()];
                break;
            case 'reject':
                $this->requireStatus($documentRequest, ['pending', 'processing']);
                $updates += ['status' => 'rejected', 'rejected_at' => now()];
                break;
            case 'process':
                $this->requireStatus($documentRequest, ['processing']);
                $updates['processed_at'] = now();
                break;
            case 'ready_for_release':
                $this->requireStatus($documentRequest, ['processing']);

                $updates += [
                    'status' => 'ready_for_release',
                    'ready_for_release_at' => now(),
                ];
                break;

            case 'release':
                $this->requireStatus($documentRequest, ['ready_for_release']);

                $updates += [
                    'status' => 'released',
                    'released_at' => now(),
                    'release_date' => today(),
                ];
                break;
            case 'cancel':
                $this->requireStatus($documentRequest, ['pending', 'processing', 'ready_for_release']);
                $updates['status'] = 'cancelled';
                break;
        }
        $documentRequest->update($updates);

        return $this->ok($documentRequest->fresh()->load($this->detailRelations()), 'Document request updated successfully.');
    }

    public function appointments(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,confirmed'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Appointment::query()
            ->select(['id', 'student_id', 'document_request_id', 'appointment_date', 'appointment_time', 'status', 'remarks'])
            ->whereNotNull('document_request_id')
            ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES)
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'documentRequest:id,document_type_id',
                'documentRequest.documentType:id,document_name',
            ])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time');
        if ($request->filled('date')) {
            $query->whereDate('appointment_date', $request->input('date'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $items = $request->has('page') ? $query->paginate(50) : $query->get();

        return $this->ok($items, 'Appointments retrieved successfully.');
    }

    public function updateAppointment(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $staff = $this->staffFor($request);
        $updates = $request->validated();
        $updates['registrar_staff_id'] = $staff->id;
        $status = $updates['status'] ?? $appointment->status;
        $date = $updates['appointment_date'] ?? $appointment->appointment_date->toDateString();
        $time = $updates['appointment_time'] ?? substr((string) $appointment->appointment_time, 0, 5);
        if (in_array($status, ['pending', 'confirmed'], true)) {
            $updates['active_slot_key'] = $date.' '.$time;
        } else {
            $updates['active_slot_key'] = null;
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
            'documentRequest:id,document_type_id',
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
        if (! $search) {
            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder->whereHas('student', fn (Builder $students) => $students->where('student_number', 'like', "%{$search}%"))
                ->orWhereHas('student.user.profile', fn (Builder $profiles) => $profiles->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                ->orWhereHas('documentType', fn (Builder $types) => $types->where('document_name', 'like', "%{$search}%"));
        });
    }

    private function applyAppointmentSearch(Builder $query, ?string $search): void
    {
        if (! $search) {
            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder->whereHas('student', fn (Builder $students) => $students->where('student_number', 'like', "%{$search}%"))
                ->orWhereHas('student.user.profile', fn (Builder $profiles) => $profiles->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                ->orWhereHas('documentRequest.documentType', fn (Builder $types) => $types->where('document_name', 'like', "%{$search}%"));
        });
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
            'appointments:id,document_request_id,appointment_date,appointment_time,status,remarks',
        ];
    }
}

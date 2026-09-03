<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelStudentAppointmentRequest;
use App\Http\Requests\CancelStudentDocumentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Appointment;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Student;
use App\Rules\AppointmentDateAvailable;
use App\Services\AppointmentSlotService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentDocumentRequestController extends Controller
{
    public function __construct(private readonly AppointmentSlotService $appointmentSlots) {}

    public function documentTypes(): JsonResponse
    {
        return $this->ok(DocumentType::query()->where('status', 'active')->orderBy('document_name')->get(['id', 'document_name', 'description', 'processing_fee', 'processing_days', 'requires_appointment']), 'Document types retrieved successfully.');
    }

    public function index(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);
        $items = $student->documentRequests()
            ->select(['id', 'student_id', 'document_type_id', 'quantity', 'total_fee', 'purpose', 'status', 'request_date', 'release_date', 'remarks', 'cancellation_reason', 'cancelled_at', 'ready_for_release_at', 'released_at', 'created_at'])
            ->with([
                'documentType:id,document_name,requires_appointment',
                'appointments:id,document_request_id,appointment_date,appointment_time,status,remarks',
            ])
            ->latest()
            ->get();

        return $this->ok($items, 'Your document requests retrieved successfully.');
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $student = $this->studentFor($request);
        $documentType = DocumentType::query()->where('status', 'active')->findOrFail($request->integer('document_type_id'));
        $quantity = $request->integer('quantity', 1);
        $item = DocumentRequest::create([
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'quantity' => $quantity,
            'total_fee' => $documentType->processing_fee * $quantity,
            'purpose' => $request->input('purpose'),
            'status' => 'pending',
            'request_date' => today(),
        ]);

        return $this->ok($item->load(['documentType:id,document_name,requires_appointment', 'appointments:id,document_request_id,appointment_date,appointment_time,status,remarks']), 'Document request submitted successfully.', 201);
    }

    public function show(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('view', $documentRequest);

        return $this->ok($documentRequest->load(['documentType', 'appointments']), 'Document request retrieved successfully.');
    }

    public function slots(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:today', new AppointmentDateAvailable],
        ]);
        $date = $request->string('date')->toString();
        $slots = $this->appointmentSlots->slotsForDate($date);

        return $this->ok([
            'date' => $date,
            'slots' => $slots,
            'unavailable_reason' => $this->appointmentSlots->dateUnavailableReason($slots),
        ], 'Appointment slots retrieved successfully.');
    }

    public function book(StoreAppointmentRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->authorize('view', $documentRequest);
        if (in_array($documentRequest->status, ['rejected', 'cancelled', 'released'], true)) {
            return response()->json(['success' => false, 'message' => 'This request can no longer be scheduled.'], 422);
        }
        if (! $documentRequest->documentType->requires_appointment) {
            return response()->json(['success' => false, 'message' => 'This document type does not require an appointment.'], 422);
        }
        $date = $request->string('appointment_date')->toString();
        $time = $request->string('appointment_time')->toString();
        if ($documentRequest->appointments()->whereIn('status', ['pending', 'confirmed'])->exists()) {
            return response()->json(['success' => false, 'message' => 'This request already has an active appointment.'], 422);
        }
        if (! $this->appointmentSlots->isAvailable($date, $time)) {
            return response()->json(['success' => false, 'message' => 'The selected appointment time is no longer available.'], 422);
        }
        try {
            $appointment = DB::transaction(fn () => Appointment::create([
                'student_id' => $documentRequest->student_id,
                'document_request_id' => $documentRequest->id,
                'appointment_date' => $date,
                'appointment_time' => $time,
                'purpose' => $request->input('purpose') ?: ($documentRequest->purpose ?: 'Document request'),
                'status' => 'pending',
                'active_slot_key' => $this->appointmentSlots->slotKey($date, $time),
            ]));
        } catch (QueryException) {
            return response()->json(['success' => false, 'message' => 'That appointment slot was just booked. Please choose another time.'], 409);
        }

        return $this->ok($appointment->load('documentRequest.documentType'), 'Appointment booked successfully.', 201);
    }

    public function appointments(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);

        return $this->ok($student->appointments()->with('documentRequest.documentType')->orderByDesc('appointment_date')->orderByDesc('appointment_time')->get(), 'Your appointments retrieved successfully.');
    }

    public function appointmentOverview(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);
        $requests = $student->documentRequests()
            ->select(['id', 'student_id', 'document_type_id', 'status'])
            ->whereIn('status', ['pending', 'processing', 'ready_for_release'])
            ->whereHas('documentType', fn ($types) => $types->where('requires_appointment', true))
            ->whereDoesntHave('appointments', fn ($appointments) => $appointments->whereIn('status', ['pending', 'confirmed']))
            ->with('documentType:id,document_name,requires_appointment')
            ->latest()
            ->get();
        $appointments = $student->appointments()
            ->select(['id', 'student_id', 'document_request_id', 'appointment_date', 'appointment_time', 'status', 'remarks'])
            ->whereNotNull('document_request_id')
            ->with(
                'documentRequest:id,document_type_id,status',
                'documentRequest.documentType:id,document_name,requires_appointment',
            )
            ->orderByDesc('appointment_date')
            ->orderByDesc('appointment_time')
            ->get();

        return $this->ok([
            'requests_needing_appointment' => $requests,
            'appointments' => $appointments,
        ], 'Appointment overview retrieved successfully.');
    }

    public function cancelDocumentRequest(
        CancelStudentDocumentRequest $request,
        DocumentRequest $documentRequest,
    ): JsonResponse {
        $student = $this->studentFor($request);
        $reason = $request->string('reason')->trim()->toString();

        $cancelledRequest = DB::transaction(function () use ($documentRequest, $reason, $student): DocumentRequest {
            $lockedRequest = DocumentRequest::query()
                ->lockForUpdate()
                ->findOrFail($documentRequest->id);

            abort_unless($lockedRequest->student_id === $student->id, 403, 'You can only cancel your own document request.');
            abort_unless(
                $lockedRequest->status === 'pending',
                422,
                'This document request can no longer be cancelled.',
            );

            $activeAppointments = Appointment::query()
                ->where('document_request_id', $lockedRequest->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->lockForUpdate()
                ->get();

            $lockedRequest->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            foreach ($activeAppointments as $appointment) {
                $cancellationNote = "Request cancelled by student: {$reason}";
                $appointment->update([
                    'status' => 'cancelled',
                    'remarks' => $appointment->remarks
                        ? $appointment->remarks."\n\n".$cancellationNote
                        : $cancellationNote,
                    'cancelled_at' => now(),
                    'active_slot_key' => null,
                ]);
            }

            return $lockedRequest;
        });

        return $this->ok(
            $cancelledRequest->fresh()->load([
                'documentType:id,document_name,requires_appointment',
                'appointments:id,document_request_id,appointment_date,appointment_time,status,remarks,cancelled_at',
            ]),
            'Document request cancelled successfully.',
        );
    }

    public function cancelAppointment(
        CancelStudentAppointmentRequest $request,
        Appointment $appointment,
    ): JsonResponse {
        $student = $this->studentFor($request);

        $cancelledAppointment = DB::transaction(function () use ($appointment, $request, $student): Appointment {
            $lockedAppointment = Appointment::query()
                ->with('documentRequest')
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            abort_unless($lockedAppointment->student_id === $student->id, 403, 'You can only cancel your own appointment.');
            abort_unless(
                in_array($lockedAppointment->status, ['pending', 'confirmed'], true),
                422,
                'This appointment can no longer be cancelled.',
            );
            abort_if(
                $lockedAppointment->documentRequest
                    && in_array($lockedAppointment->documentRequest->status, ['released', 'rejected', 'cancelled'], true),
                422,
                'This appointment cannot be cancelled because its document request is already finalized.',
            );

            $lockedAppointment->update([
                'status' => 'cancelled',
                'remarks' => $request->string('reason')->trim()->toString(),
                'cancelled_at' => now(),
                'active_slot_key' => null,
            ]);

            return $lockedAppointment;
        });

        return $this->ok(
            $cancelledAppointment->fresh()->load('documentRequest.documentType'),
            'Appointment cancelled successfully.',
        );
    }

    private function studentFor(Request $request): Student
    {
        $student = $request->user()->student;
        abort_unless($student instanceof Student, 403, 'A student profile is required for this feature.');

        return $student;
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}

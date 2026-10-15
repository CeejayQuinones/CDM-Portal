<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\DocumentRequest;
use App\Models\Student;
use App\Models\StudentRecordLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RegistrarDashboardController extends Controller
{
    private const BUSINESS_TIMEZONE = 'Asia/Manila';

    private const RECENT_LIMIT = 8;

    public function __invoke(): JsonResponse
    {
        $today = Carbon::now(self::BUSINESS_TIMEZONE)->toDateString();

        $summary = [
            'total_students' => Student::query()->count(),
            'pending_document_requests' => DocumentRequest::query()->where('status', 'pending')->count(),
            'todays_appointments' => Appointment::query()->whereDate('appointment_date', $today)->count(),
            'students_without_record_location' => Student::query()->whereDoesntHave('physicalRecordLocation')->count(),
            'students_with_missing_documents' => Student::query()
                ->whereHas('documents', fn ($query) => $query->where('availability_status', 'missing'))
                ->count(),
        ];

        $appointments = Appointment::query()
            ->select([
                'id', 'student_id', 'document_request_id', 'appointment_date', 'appointment_time',
                'purpose', 'status', 'created_at', 'updated_at',
            ])
            ->whereDate('appointment_date', $today)
            ->with([
                'student:id,user_id,student_number',
                'student.user:id',
                'student.user.profile:id,user_id,first_name,last_name',
                'documentRequest:id,document_type_id',
                'documentRequest.documentType:id,document_name',
            ])
            ->orderBy('appointment_time')
            ->orderBy('id')
            ->limit(8)
            ->get()
            ->map(fn (Appointment $appointment): array => $this->appointmentSummary($appointment));

        return response()->json([
            'success' => true,
            'message' => 'Registrar dashboard retrieved successfully.',
            'data' => [
                'summary' => $summary,
                'recent_activity' => $this->recentActivity(),
                'todays_appointments' => $appointments,
            ],
        ]);
    }

    private function recentActivity(): Collection
    {
        $requests = DocumentRequest::query()
            ->select([
                'id', 'student_id', 'document_type_id', 'status', 'created_at', 'updated_at',
                'approved_at', 'completed_at', 'rejected_at', 'cancelled_at',
            ])
            ->with([
                ...$this->studentRelations(),
                'documentType:id,document_name',
            ])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (DocumentRequest $request): array => $this->requestActivity($request));

        $appointments = Appointment::query()
            ->select(['id', 'student_id', 'document_request_id', 'status', 'created_at', 'updated_at'])
            ->with([
                ...$this->studentRelations(),
                'documentRequest:id,document_type_id',
                'documentRequest.documentType:id,document_name',
            ])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Appointment $appointment): array => $this->appointmentActivity($appointment));

        $locations = StudentRecordLocation::query()
            ->select(['id', 'student_id', 'cabinet_slot_id', 'created_at', 'updated_at'])
            ->with([
                ...$this->studentRelations(),
                'cabinetSlot:id,cabinet_id,slot_code',
                'cabinetSlot.cabinet:id,cabinet_code',
            ])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (StudentRecordLocation $location): array => $this->locationActivity($location));

        return $requests
            ->concat($appointments)
            ->concat($locations)
            ->sortByDesc('occurred_at')
            ->take(self::RECENT_LIMIT)
            ->values();
    }

    private function requestActivity(DocumentRequest $request): array
    {
        [$action, $occurredAt] = match ($request->status) {
            'rejected' => ['Document request rejected', $request->rejected_at ?? $request->updated_at],
            'completed' => ['Document request completed', $request->completed_at ?? $request->updated_at],
            'cancelled' => ['Document request cancelled', $request->updated_at],
            'approved' => ['Document request approved', $request->approved_at ?? $request->updated_at],
            default => ['Document request submitted', $request->created_at],
        };

        return $this->activityItem(
            "request:{$request->id}",
            'document_request',
            $action,
            $occurredAt,
            $request->student,
            $request->documentType?->document_name,
            $request->id,
        );
    }

    private function appointmentActivity(Appointment $appointment): array
    {
        $action = $appointment->status === 'pending'
            ? 'Appointment scheduled'
            : 'Appointment '.str_replace('_', ' ', $appointment->status);

        return $this->activityItem(
            "appointment:{$appointment->id}",
            'appointment',
            ucfirst($action),
            $appointment->status === 'pending' ? $appointment->created_at : $appointment->updated_at,
            $appointment->student,
            $appointment->documentRequest?->documentType?->document_name,
            $appointment->document_request_id,
        );
    }

    private function locationActivity(StudentRecordLocation $location): array
    {
        $moved = $location->updated_at?->greaterThan($location->created_at);
        $slot = collect([
            $location->cabinetSlot?->cabinet?->cabinet_code,
            $location->cabinetSlot?->slot_code,
        ])->filter()->join(' / ');

        return $this->activityItem(
            "record-location:{$location->id}",
            'record_location',
            $moved ? 'Physical record moved' : 'Physical record location assigned',
            $location->updated_at,
            $location->student,
            $slot !== '' ? $slot : null,
        );
    }

    private function activityItem(
        string $id,
        string $type,
        string $action,
        mixed $occurredAt,
        mixed $student,
        ?string $detail = null,
        ?int $requestId = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'action' => $action,
            'occurred_at' => Carbon::parse($occurredAt)->toISOString(),
            'student_name' => $this->studentName($student),
            'student_number' => $student?->student_number,
            'detail' => $detail,
            'request_id' => $requestId,
        ];
    }

    private function appointmentSummary(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'student_name' => $this->studentName($appointment->student),
            'student_number' => $appointment->student?->student_number,
            'document' => $appointment->documentRequest?->documentType?->document_name ?? $appointment->purpose,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'appointment_time' => substr((string) $appointment->appointment_time, 0, 5),
            'status' => $appointment->status,
        ];
    }

    private function studentName(mixed $student): string
    {
        $profile = $student?->user?->profile;

        return collect([$profile?->first_name, $profile?->last_name])->filter()->join(' ') ?: 'Unknown student';
    }

    private function studentRelations(): array
    {
        return [
            'student:id,user_id,student_number',
            'student.user:id',
            'student.user.profile:id,user_id,first_name,last_name',
        ];
    }
}

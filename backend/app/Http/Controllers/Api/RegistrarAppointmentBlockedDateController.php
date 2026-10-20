<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentBlockedDateRequest;
use App\Http\Requests\UpdateAppointmentBlockedDateRequest;
use App\Models\Appointment;
use App\Models\AppointmentBlockedDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RegistrarAppointmentBlockedDateController extends Controller
{
    private const ACTIVE_APPOINTMENT_STATUSES = ['pending', 'confirmed'];

    public function index(): JsonResponse
    {
        $blockedDates = AppointmentBlockedDate::query()
            ->orderByDesc('blocked_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AppointmentBlockedDate $blockedDate): array => $this->serialize($blockedDate));

        return $this->ok($blockedDates, 'Appointment blocked dates retrieved successfully.');
    }

    public function store(StoreAppointmentBlockedDateRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $attributes['reason'] = trim($attributes['reason']);
        $attributes['is_active'] = $attributes['is_active'] ?? true;
        $this->ensureNotDuplicate($attributes);

        $blockedDate = AppointmentBlockedDate::create([
            ...$attributes,
            'created_by' => $request->user()->id,
        ]);

        return $this->blockedDateResponse($blockedDate, 'Appointment date blocked successfully.', 201);
    }

    public function update(
        UpdateAppointmentBlockedDateRequest $request,
        AppointmentBlockedDate $appointmentBlockedDate
    ): JsonResponse {
        $attributes = $request->validated();
        if (array_key_exists('reason', $attributes)) {
            $attributes['reason'] = trim($attributes['reason']);
        }
        $candidate = [
            'blocked_date' => $attributes['blocked_date'] ?? $appointmentBlockedDate->blocked_date->toDateString(),
            'type' => $attributes['type'] ?? $appointmentBlockedDate->type,
            'reason' => $attributes['reason'] ?? $appointmentBlockedDate->reason,
            'is_active' => $attributes['is_active'] ?? $appointmentBlockedDate->is_active,
        ];
        $this->ensureNotDuplicate($candidate, $appointmentBlockedDate);

        $appointmentBlockedDate->update($attributes);

        return $this->blockedDateResponse(
            $appointmentBlockedDate->fresh(),
            'Appointment blocked date updated successfully.'
        );
    }

    public function destroy(AppointmentBlockedDate $appointmentBlockedDate): JsonResponse
    {
        $appointmentBlockedDate->delete();

        return $this->ok(null, 'Appointment blocked date deleted successfully.');
    }

    private function ensureNotDuplicate(array $attributes, ?AppointmentBlockedDate $ignore = null): void
    {
        if (! $attributes['is_active']) {
            return;
        }

        $duplicate = AppointmentBlockedDate::query()
            ->whereDate('blocked_date', $attributes['blocked_date'])
            ->where('type', $attributes['type'])
            ->where('reason', $attributes['reason'])
            ->where('is_active', true)
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'blocked_date' => ['An identical active block already exists for this date.'],
            ]);
        }
    }

    private function blockedDateResponse(
        AppointmentBlockedDate $blockedDate,
        string $message,
        int $status = 200
    ): JsonResponse {
        $appointmentCount = $blockedDate->is_active
            ? Appointment::query()
                ->whereDate('appointment_date', $blockedDate->blocked_date)
                ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES)
                ->count()
            : 0;

        if ($appointmentCount > 0) {
            $appointmentLabel = $appointmentCount === 1 ? 'appointment' : 'appointments';
            $message .= " This date currently has {$appointmentCount} active {$appointmentLabel}.";
        }

        return $this->ok([
            ...$this->serialize($blockedDate),
            'active_appointments_count' => $appointmentCount,
        ], $message, $status);
    }

    private function serialize(AppointmentBlockedDate $blockedDate): array
    {
        return [
            'id' => $blockedDate->id,
            'blocked_date' => $blockedDate->blocked_date->toDateString(),
            'type' => $blockedDate->type,
            'reason' => $blockedDate->reason,
            'is_active' => $blockedDate->is_active,
            'created_at' => $blockedDate->created_at?->toISOString(),
            'updated_at' => $blockedDate->updated_at?->toISOString(),
        ];
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentBlockedDateRequest;
use App\Http\Requests\UpdateAppointmentAvailabilitySettingsRequest;
use App\Http\Requests\UpdateAppointmentBlockedDateRequest;
use App\Models\AppointmentBlockedDate;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentAvailabilityController extends Controller
{
    public function __construct(private readonly AppointmentAvailabilityService $availability) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        return $this->ok(
            $this->availability->availabilityForMonth($validated['month']),
            'Appointment availability retrieved successfully.',
        );
    }

    public function settings(): JsonResponse
    {
        return $this->ok($this->availability->settings(), 'Appointment availability settings retrieved successfully.');
    }

    public function updateSettings(UpdateAppointmentAvailabilitySettingsRequest $request): JsonResponse
    {
        $settings = $this->availability->updateSettings($request->validated(), $request->user()->id);

        return $this->ok([
            'block_saturday' => $settings->block_saturday,
            'block_sunday' => $settings->block_sunday,
        ], 'Weekend availability settings updated successfully.');
    }

    public function blockedDates(): JsonResponse
    {
        return $this->ok(
            AppointmentBlockedDate::query()
                ->orderByDesc('blocked_date')
                ->get(['id', 'blocked_date', 'type', 'reason', 'is_active']),
            'Blocked dates retrieved successfully.',
        );
    }

    public function storeBlockedDate(StoreAppointmentBlockedDateRequest $request): JsonResponse
    {
        $blockedDate = AppointmentBlockedDate::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return $this->ok($blockedDate, 'Blocked date created successfully.', 201);
    }

    public function updateBlockedDate(
        UpdateAppointmentBlockedDateRequest $request,
        AppointmentBlockedDate $appointmentBlockedDate,
    ): JsonResponse {
        $appointmentBlockedDate->update($request->validated());

        return $this->ok($appointmentBlockedDate, 'Blocked date updated successfully.');
    }

    public function destroyBlockedDate(AppointmentBlockedDate $appointmentBlockedDate): JsonResponse
    {
        $appointmentBlockedDate->delete();

        return $this->ok(null, 'Blocked date deleted successfully.');
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}

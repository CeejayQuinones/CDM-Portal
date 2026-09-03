<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppointmentAvailabilitySettingsRequest;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentAvailabilityController extends Controller
{
    public function __construct(private readonly AppointmentAvailabilityService $availability) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $availability = $this->availability->availabilityForMonth($validated['month']);

        return response()->json([
            'success' => true,
            'message' => 'Appointment availability retrieved successfully.',
            'data' => [
                ...$availability,
                'weekends_blocked' => $availability['settings']['block_saturday']
                    && $availability['settings']['block_sunday'],
            ],
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Appointment availability settings retrieved successfully.',
            'data' => $this->availability->settings(),
        ]);
    }

    public function updateSettings(UpdateAppointmentAvailabilitySettingsRequest $request): JsonResponse
    {
        $settings = $this->availability->updateSettings($request->validated(), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Weekend availability settings updated successfully.',
            'data' => [
                'block_saturday' => $settings->block_saturday,
                'block_sunday' => $settings->block_sunday,
            ],
        ]);
    }
}

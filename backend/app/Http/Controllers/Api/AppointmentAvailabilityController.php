<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppointmentBlockedDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentAvailabilityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $start = CarbonImmutable::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfMonth();
        $nextMonth = $start->addMonth();

        $blockedDates = AppointmentBlockedDate::query()
            ->where('is_active', true)
            ->where('blocked_date', '>=', $start->toDateString())
            ->where('blocked_date', '<', $nextMonth->toDateString())
            ->orderBy('blocked_date')
            ->orderBy('id')
            ->get(['blocked_date', 'type', 'reason'])
            ->map(fn (AppointmentBlockedDate $blockedDate): array => [
                'date' => $blockedDate->blocked_date->toDateString(),
                'type' => $blockedDate->type,
                'reason' => $blockedDate->reason,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Appointment availability retrieved successfully.',
            'data' => [
                'blocked_dates' => $blockedDates,
                'weekends_blocked' => true,
            ],
        ]);
    }
}

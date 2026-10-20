<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppointmentBlockedDate;
use Illuminate\Http\JsonResponse;

class HolidayController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $holidays = AppointmentBlockedDate::query()
            ->where('type', 'holiday')
            ->where('is_active', true)
            ->orderBy('blocked_date')
            ->get(['reason', 'blocked_date'])
            ->map(fn (AppointmentBlockedDate $holiday): array => [
                'date' => $holiday->blocked_date->toDateString(),
                'name' => $holiday->reason,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Active holidays retrieved successfully.',
            'data' => $holidays,
        ]);
    }
}

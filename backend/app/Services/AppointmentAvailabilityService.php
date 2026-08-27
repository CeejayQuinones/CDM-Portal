<?php

namespace App\Services;

use App\Models\AppointmentBlockedDate;
use Carbon\CarbonImmutable;

class AppointmentAvailabilityService
{
    public function unavailableMessage(string $date): ?string
    {
        $appointmentDate = CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay();

        if ($appointmentDate->isWeekend()) {
            return 'This date is unavailable because appointments are closed on weekends.';
        }

        $blockedDates = AppointmentBlockedDate::query()
            ->whereDate('blocked_date', $date)
            ->where('is_active', true);

        if (! $blockedDates->exists()) {
            return null;
        }

        $reason = $blockedDates->orderBy('id')->value('reason');

        return $reason
            ? "This date is unavailable due to {$reason}."
            : 'This date is unavailable for appointments.';
    }
}

<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

class AppointmentSlotService
{
    private const BUSINESS_TIMEZONE = 'Asia/Manila';

    private const SLOT_TIMES = ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00'];

    private const SLOT_CAPACITY = 1;

    /** @return array<int, array{time: string, label: string, available: bool, reason: ?string}> */
    public function slotsForDate(string $date): array
    {
        $activeCounts = Appointment::query()
            ->whereDate('appointment_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get(['appointment_time'])
            ->countBy(fn (Appointment $appointment): string => substr((string) $appointment->appointment_time, 0, 5));

        return collect(self::SLOT_TIMES)
            ->map(function (string $time) use ($activeCounts, $date): array {
                $isPast = $this->isPast($date, $time);
                $isFull = $activeCounts->get($time, 0) >= self::SLOT_CAPACITY;

                return [
                    'time' => $time,
                    'label' => CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A'),
                    'available' => ! $isPast && ! $isFull,
                    'reason' => $isPast ? 'Unavailable' : ($isFull ? 'Full' : null),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, array{available: bool, reason: ?string}> $slots */
    public function dateUnavailableReason(array $slots): ?string
    {
        if (collect($slots)->contains(fn (array $slot): bool => $slot['available'])) {
            return null;
        }

        return collect($slots)->every(fn (array $slot): bool => $slot['reason'] === 'Full')
            ? 'All appointment times are full for this date.'
            : 'No appointment times are available for this date.';
    }

    public function isAvailable(string $date, string $time): bool
    {
        if (! in_array($time, self::SLOT_TIMES, true) || $this->isPast($date, $time)) {
            return false;
        }

        return ! Appointment::query()
            ->where(function ($appointments) use ($date, $time): void {
                $appointments->where('active_slot_key', $this->slotKey($date, $time))
                    ->orWhere(function ($legacyAppointments) use ($date, $time): void {
                        $legacyAppointments->whereDate('appointment_date', $date)
                            ->where('appointment_time', 'like', $time.'%');
                    });
            })
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
    }

    public function slotKey(string $date, string $time): string
    {
        return $date.' '.$time;
    }

    private function isPast(string $date, string $time): bool
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            "{$date} {$time}",
            self::BUSINESS_TIMEZONE,
        )->lessThanOrEqualTo(CarbonImmutable::now(self::BUSINESS_TIMEZONE));
    }
}

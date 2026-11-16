<?php

namespace App\Services;

use App\Models\AppointmentAvailabilitySetting;
use App\Models\Appointment;
use App\Models\AppointmentBlockedDate;
use App\Models\AppointmentDateCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentAvailabilityService
{
    /** @return array{block_saturday: bool, block_sunday: bool} */
    public function settings(): array
    {
        $settings = $this->settingsModel();

        return [
            'block_saturday' => $settings->block_saturday,
            'block_sunday' => $settings->block_sunday,
        ];
    }

    /**
     * @param  array{block_saturday: bool, block_sunday: bool}  $values
     */
    public function updateSettings(array $values, int $updatedBy): AppointmentAvailabilitySetting
    {
        return DB::transaction(function () use ($values, $updatedBy): AppointmentAvailabilitySetting {
            $settings = AppointmentAvailabilitySetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            foreach (['block_saturday', 'block_sunday'] as $field) {
                if (! $values[$field] || $settings->{$field}) {
                    continue;
                }
                $weekday = $field === 'block_saturday' ? 6 : 0;
                $appointment = Appointment::query()
                    ->select('appointment_date')
                    ->distinct()
                    ->whereDate('appointment_date', '>=', CarbonImmutable::now('Asia/Manila')->toDateString())
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->orderBy('appointment_date')
                    ->cursor()
                    ->first(fn (Appointment $item) => $item->appointment_date->dayOfWeek === $weekday);
                if ($appointment !== null) {
                    $day = $appointment->appointment_date->toDateString();
                    throw ValidationException::withMessages([
                        $field => ["{$day} has an active appointment. Reschedule it before blocking this weekend day."],
                    ]);
                }
            }
            $settings->update([...$values, 'updated_by' => $updatedBy]);

            return $settings;
        });
    }

    public function ensureDateCanClose(string $date): void
    {
        // Booking and closure changes lock the same date row before checking capacity.
        AppointmentDateCapacity::query()->insertOrIgnore([
            'appointment_date' => $date,
            'capacity' => DocumentRequestWorkflowService::DEFAULT_CAPACITY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AppointmentDateCapacity::query()->whereDate('appointment_date', $date)->lockForUpdate()->firstOrFail();
        $booked = Appointment::query()->whereDate('appointment_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])->count();
        if ($booked > 0) {
            throw ValidationException::withMessages([
                'blocked_date' => ["{$date} has {$booked} active ".($booked === 1 ? 'appointment' : 'appointments').'. Reschedule or cancel '.($booked === 1 ? 'it' : 'them').' before closing this date.'],
            ]);
        }
    }

    /** @return array{settings: array{block_saturday: bool, block_sunday: bool}, blocked_dates: array<int, array{id: int, date: string, type: string, reason: string}>} */
    public function availabilityForMonth(string $month): array
    {
        $firstDay = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $nextMonth = $firstDay->addMonth();
        $blockedDates = AppointmentBlockedDate::query()
            ->select(['id', 'blocked_date', 'type', 'reason'])
            ->where('is_active', true)
            ->where('blocked_date', '>=', $firstDay->toDateString())
            ->where('blocked_date', '<', $nextMonth->toDateString())
            ->orderBy('blocked_date')
            ->orderBy('id')
            ->get()
            ->map(fn (AppointmentBlockedDate $blockedDate): array => [
                'id' => $blockedDate->id,
                'date' => $blockedDate->blocked_date->format('Y-m-d'),
                'type' => $blockedDate->type,
                'reason' => $blockedDate->reason,
            ])
            ->all();

        return [
            'settings' => $this->settings(),
            'blocked_dates' => $blockedDates,
        ];
    }

    public function unavailableMessage(string $date): ?string
    {
        $appointmentDate = CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay();
        $settings = $this->settings();

        if (
            ($appointmentDate->isSaturday() && $settings['block_saturday'])
            || ($appointmentDate->isSunday() && $settings['block_sunday'])
        ) {
            return 'This date is unavailable because appointments are closed on weekends.';
        }

        $blockedDates = AppointmentBlockedDate::query()
            ->whereDate('blocked_date', $date)
            ->where('is_active', true);

        if (! $blockedDates->exists()) {
            // Keep older holiday rows effective, but let a registrar's inactive
            // appointment holiday override the legacy table for the same date.
            $hasAppointmentHoliday = AppointmentBlockedDate::query()
                ->whereDate('blocked_date', $date)
                ->where('type', 'holiday')
                ->exists();
            $holiday = $hasAppointmentHoliday ? null : DB::table('holidays')
                ->whereDate('holiday_date', $date)->where('is_active', true)->value('name');

            if ($holiday) {
                return "This date is unavailable due to {$holiday}.";
            }

            return null;
        }

        $reason = $blockedDates->orderBy('id')->value('reason');

        return $reason
            ? "This date is unavailable due to {$reason}."
            : 'This date is unavailable for appointments.';
    }

    private function settingsModel(): AppointmentAvailabilitySetting
    {
        return AppointmentAvailabilitySetting::query()->firstOrCreate(
            ['id' => 1],
            ['block_saturday' => true, 'block_sunday' => true],
        );
    }
}

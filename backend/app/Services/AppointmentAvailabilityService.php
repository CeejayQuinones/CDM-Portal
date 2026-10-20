<?php

namespace App\Services;

use App\Models\AppointmentAvailabilitySetting;
use App\Models\AppointmentBlockedDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        $settings = $this->settingsModel();
        $settings->update([...$values, 'updated_by' => $updatedBy]);

        return $settings;
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

        $holiday = DB::table('holidays')->whereDate('holiday_date', $date)->where('is_active', true)->value('name');
        if ($holiday) {
            return "This date is unavailable due to {$holiday}.";
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

    private function settingsModel(): AppointmentAvailabilitySetting
    {
        return AppointmentAvailabilitySetting::query()->firstOrCreate(
            ['id' => 1],
            ['block_saturday' => true, 'block_sunday' => true],
        );
    }
}

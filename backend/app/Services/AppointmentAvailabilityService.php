<?php

namespace App\Services;

use App\Models\AppointmentAvailabilitySetting;
use App\Models\AppointmentBlockedDate;
use Carbon\CarbonImmutable;
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
        $settings = $this->settingsModel();
        $settings->update([...$values, 'updated_by' => $updatedBy]);

        return $settings;
    }

    /** @return array{settings: array{block_saturday: bool, block_sunday: bool}, blocked_dates: array<int, array{id: int, date: string, type: string, reason: string}>} */
    public function availabilityForMonth(string $month): array
    {
        $firstDay = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');
        $blockedDates = AppointmentBlockedDate::query()
            ->select(['id', 'blocked_date', 'type', 'reason'])
            ->where('is_active', true)
            ->whereBetween('blocked_date', [$firstDay->toDateString(), $firstDay->endOfMonth()->toDateString()])
            ->orderBy('blocked_date')
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

    public function ensureDateIsAvailable(string $date): void
    {
        $requestedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $settings = $this->settings();

        if ($requestedDate->isSaturday() && $settings['block_saturday']) {
            $this->reject('Appointments are unavailable on Saturdays.');
        }

        if ($requestedDate->isSunday() && $settings['block_sunday']) {
            $this->reject('Appointments are unavailable on Sundays.');
        }

        $blockedReason = AppointmentBlockedDate::query()
            ->where('blocked_date', '>=', $requestedDate->toDateString())
            ->where('blocked_date', '<', $requestedDate->addDay()->toDateString())
            ->where('is_active', true)
            ->value('reason');

        if ($blockedReason !== null) {
            $this->reject($blockedReason.' is unavailable for appointments.');
        }
    }

    private function settingsModel(): AppointmentAvailabilitySetting
    {
        return AppointmentAvailabilitySetting::query()->firstOrCreate(
            ['id' => 1],
            ['block_saturday' => true, 'block_sunday' => true],
        );
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'appointment_date' => [$message],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppointmentAvailabilitySettingsRequest;
use App\Services\AppointmentAvailabilityService;
use App\Models\Appointment;
use App\Models\AppointmentDateCapacity;
use App\Services\DocumentRequestWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $start = CarbonImmutable::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfMonth();
        $end = $start->addMonth();
        $counts = Appointment::query()->whereBetween('appointment_date', [$start->toDateString(), $end->subDay()->toDateString()])->whereIn('status', ['pending', 'confirmed'])->selectRaw('appointment_date, COUNT(*) as aggregate')->groupBy('appointment_date')->pluck('aggregate', 'appointment_date');
        $overrides = AppointmentDateCapacity::query()->whereBetween('appointment_date', [$start->toDateString(), $end->subDay()->toDateString()])->pluck('capacity', 'appointment_date');
        $availability = $this->availability->availabilityForMonth($validated['month']);

        return response()->json(['success' => true, 'message' => 'Appointment calendar retrieved successfully.', 'data' => [
            ...$availability,
            'days' => collect(range(0, $start->daysInMonth - 1))->map(function ($offset) use ($counts, $overrides, $start): array {
                $date = $start->addDays($offset)->toDateString();
                $capacity = (int) ($overrides[$date] ?? DocumentRequestWorkflowService::DEFAULT_CAPACITY);

                return [
                    'date' => $date,
                    'booked' => (int) ($counts[$date] ?? 0),
                    'capacity' => $capacity,
                    // A default-capacity row may exist after an appointment is assigned.
                    // The planner only needs to distinguish an effective custom capacity.
                    'has_custom_capacity' => $capacity !== DocumentRequestWorkflowService::DEFAULT_CAPACITY,
                ];
            })->all(),
            'default_capacity' => DocumentRequestWorkflowService::DEFAULT_CAPACITY,
        ]]);
    }

    public function updateCapacity(Request $request, string $date): JsonResponse
    {
        $request->merge(['date' => $date]);
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'capacity' => ['required', 'integer', 'min:1', 'max:100']]);
        $row = DB::transaction(function () use ($date, $request, $validated): AppointmentDateCapacity {
            AppointmentDateCapacity::query()->insertOrIgnore(['appointment_date' => $date, 'capacity' => DocumentRequestWorkflowService::DEFAULT_CAPACITY, 'created_at' => now(), 'updated_at' => now()]);
            $capacity = AppointmentDateCapacity::query()->whereDate('appointment_date', $date)->lockForUpdate()->firstOrFail();
            $booked = Appointment::query()->whereDate('appointment_date', $date)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->count();
            abort_if($validated['capacity'] < $booked, 422, 'Capacity cannot be lower than the current booked count.');
            $capacity->update(['capacity' => $validated['capacity'], 'updated_by' => $request->user()->id]);
            return $capacity;
        });
        return response()->json(['success' => true, 'message' => 'Date capacity updated successfully.', 'data' => $row]);
    }
}

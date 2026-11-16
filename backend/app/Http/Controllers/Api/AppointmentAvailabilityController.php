<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppointmentAvailabilitySettingsRequest;
use App\Models\Appointment;
use App\Models\AppointmentDateCapacity;
use App\Models\AppointmentWindowCapacity;
use App\Models\AppointmentWindowDefault;
use App\Models\AppointmentAvailabilitySetting;
use App\Services\AppointmentAvailabilityService;
use App\Services\DocumentRequestWorkflowService;
use App\Services\RegistrarWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $windowCounts = Appointment::query()->whereBetween('appointment_date', [$start->toDateString(), $end->subDay()->toDateString()])->whereIn('status', ['pending', 'confirmed'])->selectRaw('appointment_date, service_window, COUNT(*) as aggregate')->groupBy('appointment_date', 'service_window')->get()->groupBy(fn ($row) => substr((string) $row->appointment_date, 0, 10));
        $overrides = AppointmentDateCapacity::query()->whereBetween('appointment_date', [$start->toDateString(), $end->subDay()->toDateString()])->pluck('capacity', 'appointment_date');
        $windowOverrides = AppointmentWindowCapacity::query()->whereNotNull('updated_by')->whereBetween('appointment_date', [$start->toDateString(), $end->subDay()->toDateString()])->get()->groupBy(fn ($row) => substr((string) $row->appointment_date, 0, 10));
        $windowDefaults = AppointmentWindowDefault::query()->pluck('capacity', 'service_window');
        $availability = $this->availability->availabilityForMonth($validated['month']);

        return response()->json(['success' => true, 'message' => 'Appointment calendar retrieved successfully.', 'data' => [
            ...$availability,
            'days' => collect(range(0, $start->daysInMonth - 1))->map(function ($offset) use ($counts, $overrides, $windowCounts, $windowOverrides, $windowDefaults, $start): array {
                $date = $start->addDays($offset)->toDateString();
                $capacity = (int) ($overrides[$date] ?? DocumentRequestWorkflowService::DEFAULT_CAPACITY);
                $unassigned = (int) ($windowCounts->get($date)?->firstWhere('service_window', null)?->aggregate ?? 0);

                return [
                    'date' => $date,
                    'booked' => (int) ($counts[$date] ?? 0),
                    'capacity' => $capacity,
                    // A default-capacity row may exist after an appointment is assigned.
                    // The planner only needs to distinguish an effective custom capacity.
                    'has_custom_capacity' => $capacity !== DocumentRequestWorkflowService::DEFAULT_CAPACITY,
                    'unassigned_window_booked' => $unassigned,
                    'windows' => collect(RegistrarWindowService::WINDOWS)->mapWithKeys(function (string $window) use ($date, $unassigned, $windowCounts, $windowOverrides, $windowDefaults): array {
                        $booked = (int) ($windowCounts->get($date)?->firstWhere('service_window', $window)?->aggregate ?? 0);
                        $override = $windowOverrides->get($date)?->firstWhere('service_window', $window);
                        $limit = (int) ($override?->capacity ?? $windowDefaults[$window] ?? DocumentRequestWorkflowService::DEFAULT_WINDOW_CAPACITY);

                        return [$window => ['booked' => $booked, 'unassigned' => $unassigned, 'capacity' => $limit, 'has_custom_capacity' => $override !== null]];
                    })->all(),
                ];
            })->all(),
            'default_capacity' => DocumentRequestWorkflowService::DEFAULT_CAPACITY,
            'default_window_capacity' => DocumentRequestWorkflowService::DEFAULT_WINDOW_CAPACITY,
            'window_defaults' => collect(RegistrarWindowService::WINDOWS)->mapWithKeys(fn (string $window) => [$window => (int) ($windowDefaults[$window] ?? DocumentRequestWorkflowService::DEFAULT_WINDOW_CAPACITY)])->all(),
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

    public function updateWindowCapacity(Request $request, string $date, string $window): JsonResponse
    {
        $request->merge(['date' => $date, 'service_window' => $window]);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'service_window' => ['required', \Illuminate\Validation\Rule::in(RegistrarWindowService::WINDOWS)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $row = DB::transaction(function () use ($date, $window, $request, $validated): AppointmentWindowCapacity {
            // Share the date lock with booking, so a capacity edit cannot race a new booking.
            AppointmentDateCapacity::query()->insertOrIgnore(['appointment_date' => $date, 'capacity' => DocumentRequestWorkflowService::DEFAULT_CAPACITY, 'created_at' => now(), 'updated_at' => now()]);
            AppointmentDateCapacity::query()->whereDate('appointment_date', $date)->lockForUpdate()->firstOrFail();
            AppointmentWindowCapacity::query()->insertOrIgnore(['appointment_date' => $date, 'service_window' => $window, 'capacity' => DocumentRequestWorkflowService::DEFAULT_WINDOW_CAPACITY, 'created_at' => now(), 'updated_at' => now()]);
            $row = AppointmentWindowCapacity::query()->whereDate('appointment_date', $date)->where('service_window', $window)->lockForUpdate()->firstOrFail();
            $booked = Appointment::query()->whereDate('appointment_date', $date)->whereIn('status', ['pending', 'confirmed'])
                ->where(fn ($query) => $query->where('service_window', $window)->orWhereNull('service_window'))->count();
            abort_if($validated['capacity'] < $booked, 422, 'Window capacity cannot be lower than the booked count, including appointments without an assigned window.');
            $row->update(['capacity' => $validated['capacity'], 'updated_by' => $request->user()->id]);

            return $row;
        });

        return response()->json(['success' => true, 'message' => 'Window capacity updated successfully.', 'data' => $row]);
    }

    public function updateWindowDefault(Request $request, string $window): JsonResponse
    {
        $request->merge(['service_window' => $window]);
        $validated = $request->validate([
            'service_window' => ['required', \Illuminate\Validation\Rule::in(RegistrarWindowService::WINDOWS)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $row = DB::transaction(function () use ($request, $window, $validated): AppointmentWindowDefault {
            // Assignment locks this same settings row before reading a default.
            AppointmentAvailabilitySetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $bookedByDate = Appointment::query()
                ->whereDate('appointment_date', '>=', CarbonImmutable::now(DocumentRequestWorkflowService::BUSINESS_TIMEZONE)->toDateString())
                ->whereIn('status', ['pending', 'confirmed'])
                ->where(fn ($query) => $query->where('service_window', $window)->orWhereNull('service_window'))
                ->selectRaw('appointment_date, COUNT(*) as booked')
                ->groupBy('appointment_date')->get();
            foreach ($bookedByDate as $day) {
                $date = substr((string) $day->appointment_date, 0, 10);
                $hasOverride = AppointmentWindowCapacity::query()->whereDate('appointment_date', $date)
                    ->where('service_window', $window)->whereNotNull('updated_by')->exists();
                abort_if(! $hasOverride && $day->booked > $validated['capacity'], 422, "{$date} already has {$day->booked} appointments counted toward {$window}. Set a date-specific limit or reschedule them first.");
            }
            AppointmentWindowDefault::query()->updateOrCreate(
                ['service_window' => $window],
                ['capacity' => $validated['capacity'], 'updated_by' => $request->user()->id],
            );

            return AppointmentWindowDefault::query()->where('service_window', $window)->firstOrFail();
        });

        return response()->json(['success' => true, 'message' => 'Default window capacity updated successfully.', 'data' => $row]);
    }
}

<?php

namespace App\Services\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdmissionIdentityService
{
    public function __construct(private readonly AdmissionAuditWriter $audit) {}

    public function findForCycle(User $user, AdmissionCycle $cycle): ?AdmissionApplicant
    {
        $actor = User::query()->with('role')->findOrFail($user->id);
        Gate::forUser($actor)->authorize('viewOwn', AdmissionApplicant::class);

        return $actor->admissionApplications()->where('cycle_id', $cycle->id)->first();
    }

    public function creationAvailability(User $user): array
    {
        $actor = $user->fresh('role');
        if (! Gate::forUser($actor)->allows('create', AdmissionApplicant::class)) {
            return ['allowed' => false, 'reason' => 'permission_denied'];
        }
        if (! $actor->profile()->exists()) {
            return ['allowed' => false, 'reason' => 'profile_required'];
        }
        $cycles = AdmissionCycle::query()->where('status', AdmissionCycle::OPEN)
            ->where('opens_at', '<=', now())->where('closes_at', '>', now())->limit(2)->get();
        if ($cycles->count() !== 1) {
            return ['allowed' => false, 'reason' => $cycles->isEmpty() ? 'no_open_cycle' : 'cycle_unavailable'];
        }
        if ($actor->admissionApplications()->where(function ($query) use ($cycles) {
            $query->where('cycle_id', $cycles->first()->id)->orWhereIn('status', AdmissionApplicant::ACTIVE_STATUSES);
        })->exists()) {
            return ['allowed' => false, 'reason' => 'application_exists'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function createForOpenCycle(User $user): AdmissionApplicant
    {
        return DB::transaction(function () use ($user) {
            $actor = User::query()->with('role')->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($actor)->authorize('create', AdmissionApplicant::class);
            $availability = $this->creationAvailability($actor);
            if (! $availability['allowed']) {
                throw ValidationException::withMessages(['creation' => $availability['reason']]);
            }
            $cycles = AdmissionCycle::query()->where('status', AdmissionCycle::OPEN)
                ->where('opens_at', '<=', now())->where('closes_at', '>', now())
                ->orderBy('id')->lockForUpdate()->get();
            if ($cycles->count() !== 1) {
                throw ValidationException::withMessages(['creation' => 'cycle_unavailable']);
            }

            return $this->create($actor, $cycles->first());
        }, 3);
    }

    public function create(User $user, AdmissionCycle $cycle): AdmissionApplicant
    {
        // Retry only a random-number collision; ownership conflicts are not retries.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $cycle): AdmissionApplicant {
                    $actor = User::query()->with('role')->lockForUpdate()->findOrFail($user->id);
                    Gate::forUser($actor)->authorize('create', AdmissionApplicant::class);
                    $currentCycle = AdmissionCycle::query()->lockForUpdate()->findOrFail($cycle->id);

                    if (! $actor->profile()->exists()) {
                        throw ValidationException::withMessages(['user' => 'An existing portal profile is required.']);
                    }
                    $now = now();
                    if ($currentCycle->status !== AdmissionCycle::OPEN || ! $currentCycle->opens_at || ! $currentCycle->closes_at
                        || $now->lt($currentCycle->opens_at) || $now->gte($currentCycle->closes_at)) {
                        throw ValidationException::withMessages(['cycle' => 'This admission cycle is not open for applications.']);
                    }
                    if ($actor->admissionApplications()->where('cycle_id', $currentCycle->id)->exists()) {
                        throw ValidationException::withMessages(['cycle' => 'An application already exists for this cycle.']);
                    }
                    if ($actor->admissionApplications()->whereIn('status', AdmissionApplicant::ACTIVE_STATUSES)->exists()) {
                        throw ValidationException::withMessages(['cycle' => 'An active application already exists.']);
                    }

                    $applicant = new AdmissionApplicant;
                    $applicant->user()->associate($actor);
                    $applicant->cycle()->associate($currentCycle);
                    $applicant->save();
                    $this->audit->identityCreated($actor, $applicant);

                    return $applicant;
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                $detail = $exception->errorInfo[2] ?? '';
                if ($attempt === 2 || ! str_contains($detail, 'applicant_number')) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Applicant identity allocation did not complete.');
    }
}

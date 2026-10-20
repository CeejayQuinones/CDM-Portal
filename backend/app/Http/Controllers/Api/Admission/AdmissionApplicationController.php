<?php

namespace App\Http\Controllers\Api\Admission;

use App\Http\Controllers\Controller;
use App\Models\Admission\AdmissionApplicant;
use App\Services\Admission\AdmissionIdentityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdmissionApplicationController extends Controller
{
    public function availability(Request $request, AdmissionIdentityService $service): JsonResponse
    {
        Gate::forUser($request->user()->fresh('role'))
            ->authorize('viewOwn', AdmissionApplicant::class);
        try {
            return response()->json(['success' => true, 'data' => $service->creationAvailability($request->user())])
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('unavailable', 503);
        }
    }

    public function store(Request $request, AdmissionIdentityService $service): JsonResponse
    {
        try {
            $applicant = $service->createForOpenCycle($request->user());

            return app(AdmissionIdentityController::class)->identityResponse($applicant)->setStatusCode(201);
        } catch (AuthorizationException) {
            return $this->failure('permission_denied', 403);
        } catch (ValidationException $exception) {
            $reason = $exception->errors()['creation'][0] ?? 'cycle_unavailable';
            if (! in_array($reason, ['application_exists', 'no_open_cycle', 'cycle_unavailable', 'profile_required'], true)) {
                $reason = 'unavailable';
            }

            return $this->failure($reason, $reason === 'application_exists' ? 409 : 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('unavailable', 503);
        }
    }

    private function failure(string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => 'Admission application could not be created.'], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}

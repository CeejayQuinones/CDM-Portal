<?php

namespace App\Http\Controllers\Api\Admission;

use App\Http\Controllers\Controller;
use App\Models\Admission\AdmissionApplicant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class AdmissionIdentityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->fresh('role');
        Gate::forUser($user)->authorize('viewOwn', AdmissionApplicant::class);

        try {
            // Missing deployment prerequisites are not an empty application history.
            if (! Schema::hasTable('admission_applicants') || ! Schema::hasTable('admission_cycles')) {
                return $this->unavailable();
            }

            // No caller-supplied user, cycle or applicant identifiers are accepted.
            $applicant = $user->admissionApplications()
                ->select(['id', 'user_id', 'cycle_id', 'applicant_number', 'status', 'created_at', 'submitted_at', 'converted_at'])
                ->with('cycle:id,code,name,status,opens_at,closes_at,confirmation_closes_at')
                ->orderByDesc('created_at')->orderByDesc('id')->first();

            return $this->identityResponse($applicant);
        } catch (QueryException $exception) {
            report($exception);

            return $this->unavailable();
        }
    }

    public function identityResponse(?AdmissionApplicant $applicant): JsonResponse
    {
        $application = null;
        if ($applicant !== null) {
            $cycle = $applicant->cycle;
            if ($cycle === null) {
                return $this->unavailable();
            }
            $application = [
                'applicant_number' => $applicant->applicant_number,
                'status' => $applicant->status,
                'cycle' => [
                    'code' => $cycle->code,
                    'name' => $cycle->name,
                    'status' => $cycle->status,
                    'opens_at' => $cycle->opens_at?->toIso8601String(),
                    'closes_at' => $cycle->closes_at?->toIso8601String(),
                    'confirmation_closes_at' => $cycle->confirmation_closes_at?->toIso8601String(),
                ],
                'created_at' => $applicant->created_at?->toIso8601String(),
                'submitted_at' => $applicant->submitted_at?->toIso8601String(),
                'is_converted' => $applicant->status === AdmissionApplicant::CONVERTED,
                'converted_at' => $applicant->converted_at?->toIso8601String(),
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'has_application' => $applicant !== null,
            'application' => $application,
        ]])->header('Cache-Control', 'private, no-store');
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Unable to load admission information.'], 503)
            ->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use App\Services\StepUpAuthenticationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStepUpAuthentication
{
    public function __construct(private readonly StepUpAuthenticationService $stepUpAuthentication) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->loadMissing('role');

        if ($user->role?->role_name !== Role::REGISTRAR_STAFF) {
            return $next($request);
        }

        if (! $this->stepUpAuthentication->isValid($request)) {
            return $this->requiredResponse();
        }

        return $next($request);
    }

    private function requiredResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'STEP_UP_REQUIRED',
            'message' => 'Please verify your password to continue.',
        ], Response::HTTP_PRECONDITION_REQUIRED);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StepUpAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StepUpAuthenticationController extends Controller
{
    public function __construct(private readonly StepUpAuthenticationService $stepUpAuthentication) {}

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $expiresAt = $this->stepUpAuthentication->verify($request, $validated['password']);

        return response()->json([
            'success' => true,
            'message' => 'Identity verified.',
            'data' => [
                'expires_at' => $expiresAt->toISOString(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterGuestRequest;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\GuestRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
        private readonly GuestRegistrationService $guestRegistrationService,
    )
    {
    }

    /**
     * Register a public Guest account. Email verification will be added later.
     */
    public function register(RegisterGuestRequest $request): JsonResponse
    {
        $user = $this->guestRegistrationService->register($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. You can now sign in.',
            'data' => [
                'user' => new AuthResource($user),
            ],
        ], 201);
    }

    /**
     * Authenticate a user by username and password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $authenticated = $this->authenticationService->login($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => new AuthResource($authenticated['user']),
                'token' => $authenticated['token'],
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authenticationService->logout($user, $request->bearerToken());

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
            'data' => (object) [],
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved successfully.',
            'data' => new AuthResource($this->authenticationService->currentUser($user)),
        ]);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updatedUser = $this->authenticationService->changePassword($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => new AuthResource($updatedUser),
        ]);
    }
}

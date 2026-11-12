<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterGuestRequest;
use App\Http\Requests\SendEmailVerificationRequest;
use App\Http\Requests\VerifyEmailCodeRequest;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\ClientPlatformAccessService;
use App\Services\GuestRegistrationService;
use App\Services\LoginOtpService;
use App\Services\RegistrationEmailVerificationService;
use App\Services\StepUpAuthenticationService;
use App\Services\TurnstileVerificationService;
use App\Support\ClientPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
        private readonly ClientPlatformAccessService $clientAccess,
        private readonly GuestRegistrationService $guestRegistrationService,
        private readonly RegistrationEmailVerificationService $emailVerificationService,
        private readonly StepUpAuthenticationService $stepUpAuthentication,
        private readonly LoginOtpService $loginOtpService,
        private readonly TurnstileVerificationService $turnstile,
    ) {}

    /** Register a public Guest account after verified-email proof is supplied. */
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

    public function sendEmailVerification(SendEmailVerificationRequest $request): JsonResponse
    {
        $this->turnstile->verify(
            $request->validated('turnstile_token'),
            'registration',
            (string) $request->ip(),
        );

        if (! $this->emailVerificationService->deliveryIsConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Email delivery is not configured. Please contact the portal administrator.',
            ], 503);
        }

        $this->emailVerificationService->send($request->validated('email'));

        return response()->json([
            'success' => true,
            'message' => 'A verification code was sent to your email address.',
            'data' => ['expires_in' => RegistrationEmailVerificationService::CODE_TTL_MINUTES * 60],
        ]);
    }

    public function verifyEmail(VerifyEmailCodeRequest $request): JsonResponse
    {
        $token = $this->emailVerificationService->verify(
            $request->validated('email'),
            $request->validated('code'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Email address verified.',
            'data' => ['verification_token' => $token],
        ]);
    }

    /**
     * Authenticate a user by username and password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $client = $this->clientAccess->clientFrom($request);
        $credentials = $request->safe()->only(['username', 'password']);

        if ($client !== ClientPlatform::DESKTOP) {
            $this->turnstile->verify(
                $request->validated('turnstile_token'),
                'authentication',
                (string) $request->ip(),
            );
        }

        $user = $this->authenticationService->authenticate($credentials, $client);

        if ($client !== ClientPlatform::DESKTOP) {
            $challenge = $this->loginOtpService->create($user, $client, (string) $request->ip());

            return response()->json([
                'success' => true,
                'message' => 'A verification code was sent to your registered email address.',
                'data' => $challenge,
            ], 202);
        }

        $authenticated = $this->authenticationService->issueToken($user, $client);

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

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $client = $this->clientAccess->clientFrom($request);
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);
        $authenticated = $this->loginOtpService->verify(
            $validated['challenge_id'],
            $validated['code'],
            $client,
            (string) $request->ip(),
        );

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

    public function resendLoginOtp(Request $request): JsonResponse
    {
        $client = $this->clientAccess->clientFrom($request);
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->loginOtpService->resend(
            $validated['challenge_id'],
            $client,
            (string) $request->ip(),
        );

        return response()->json([
            'success' => true,
            'message' => 'A new verification code was sent to your registered email address.',
            'data' => $challenge,
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->stepUpAuthentication->revoke($request);
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
        $this->stepUpAuthentication->revoke($request);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => new AuthResource($updatedUser),
        ]);
    }
}

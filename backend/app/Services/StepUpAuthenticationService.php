<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class StepUpAuthenticationService
{
    public const EXPIRY_MINUTES = 5;

    /**
     * Verify the current user's password and grant step-up authorization.
     */
    public function verify(Request $request, string $password): Carbon
    {
        $user = $this->authenticatedUser($request);

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        Cache::put($this->cacheKey($request, $user), $expiresAt->getTimestamp(), $expiresAt);

        return $expiresAt;
    }

    /**
     * Determine whether this authenticated user and auth context has a valid grant.
     */
    public function isValid(Request $request): bool
    {
        $user = $this->authenticatedUser($request);
        $expiresAt = Cache::get($this->cacheKey($request, $user));

        if (! is_int($expiresAt) || $expiresAt <= now()->getTimestamp()) {
            $this->revoke($request);

            return false;
        }

        return true;
    }

    /**
     * Revoke the grant for only the current user and auth context.
     */
    public function revoke(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            Cache::forget($this->cacheKey($request, $user));
        }
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new \LogicException('Step-up authentication requires an authenticated user.');
        }

        return $user;
    }

    private function cacheKey(Request $request, User $user): string
    {
        return sprintf(
            'step-up:user:%d:context:%s',
            $user->getKey(),
            hash('sha256', $this->authContextIdentifier($request, $user)),
        );
    }

    private function authContextIdentifier(Request $request, User $user): string
    {
        $bearerToken = $request->bearerToken();

        if (is_string($bearerToken) && $bearerToken !== '') {
            return 'bearer:'.$bearerToken;
        }

        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            return 'token-id:'.$accessToken->getKey();
        }

        if ($request->hasSession()) {
            return 'session:'.$request->session()->getId();
        }

        // Sanctum's test-only transient token has no persistent token identifier.
        return 'authenticated-user-fallback:'.$user->getKey();
    }
}

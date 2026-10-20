<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleIdentityException;
use App\Http\Resources\AuthResource;
use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\ClientPlatformAccessService;
use App\Services\GoogleIdentityService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdentityService $identities,
        private readonly AuthenticationService $authentication,
        private readonly ClientPlatformAccessService $clientAccess,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $client = $request->validate(['client' => 'required|in:web'])['client'];
        $request->session()->put('google_auth_client', $client);

        return Socialite::driver('google')
            ->scopes(['openid', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request)
    {
        $client = (string) $request->session()->pull('google_auth_client', '');
        try {
            if ($client !== 'web') {
                throw new InvalidStateException;
            }
            $google = Socialite::driver('google')->user();
            $raw = $google->user;
            $user = $this->identities->authenticate(
                (string) $google->getId(),
                (string) $google->getEmail(),
                filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
                $client,
            );
            $code = Str::random(64);
            Cache::put('google-auth-handoff:'.hash('sha256', $code), ['user_id' => $user->id, 'client' => $client], now()->addMinutes(2));
            Log::info('google_login_succeeded', ['user_id' => $user->id, 'client' => $client]);

            return response()->view('auth.google-callback', ['code' => $code, 'error' => null, 'origin' => config('app.frontend_url')]);
        } catch (InvalidStateException) {
            Log::warning('google_login_failed', ['reason' => 'state_session_mismatch', 'client' => $client]);

            return response()->view('auth.google-callback', ['code' => null, 'error' => 'The Google sign-in session expired. Please try again.', 'origin' => config('app.frontend_url')], 401);
        } catch (GoogleIdentityException $exception) {
            Log::warning('google_login_failed', ['reason' => $exception->reason, 'client' => $client]);

            return response()->view('auth.google-callback', ['code' => null, 'error' => $exception->getMessage(), 'origin' => config('app.frontend_url')], 401);
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('google_login_failed', ['reason' => 'oauth_failure', 'client' => $client]);

            return response()->view('auth.google-callback', ['code' => null, 'error' => 'Google sign-in could not be completed. Please try again.', 'origin' => config('app.frontend_url')], 401);
        }
    }

    public function exchange(Request $request): JsonResponse
    {
        $client = $this->clientAccess->clientFrom($request);
        $code = $request->validate(['code' => 'required|string|size:64'])['code'];
        $handoff = Cache::pull('google-auth-handoff:'.hash('sha256', $code));
        if (! is_array($handoff) || ! hash_equals((string) ($handoff['client'] ?? ''), $client)) {
            throw new AuthenticationException('The Google sign-in session expired. Please try again.');
        }

        $user = User::query()->with(['role', 'profile'])->findOrFail($handoff['user_id']);
        $this->clientAccess->ensureUserMayUse($user, $client);
        $authenticated = $this->authentication->issueToken($user, $client);

        return response()->json(['success' => true, 'message' => 'Login successful.', 'data' => [
            'user' => new AuthResource($authenticated['user']),
            'token' => $authenticated['token'],
            'token_type' => 'Bearer',
        ]]);
    }
}

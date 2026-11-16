<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use App\Support\SanctumTokenExpiration;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    public function __construct(private readonly ClientPlatformAccessService $clientAccess) {}

    /**
     * Validate credentials and platform access without creating a session.
     * Students use their student number, Professors use email, and other roles use username.
     *
     * @param  array{username: string, password: string}  $credentials
     */
    public function authenticate(array $credentials, string $client): User
    {
        $identifier = trim($credentials['username']);
        $user = User::query()
            ->with(['role', 'profile'])
            ->whereHas('role', fn ($query) => $query->where('role_name', Role::STUDENT))
            ->whereHas('student', fn ($query) => $query->where('student_number', $identifier))
            ->first();

        $user ??= User::query()
            ->with(['role', 'profile'])
            ->whereHas('role', fn ($query) => $query->where('role_name', Role::PROFESSOR))
            ->whereHas('profile', fn ($query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower($identifier)]))
            ->first();

        $user ??= User::query()
            ->with(['role', 'profile'])
            ->where('username', $identifier)
            // Legacy student accounts without a linked student record remain usable.
            ->where(fn ($query) => $query
                ->whereHas('role', fn ($role) => $role->whereNotIn('role_name', [Role::STUDENT, Role::PROFESSOR]))
                ->orWhere(fn ($legacy) => $legacy
                    ->whereHas('role', fn ($role) => $role->where('role_name', Role::STUDENT))
                    ->whereDoesntHave('student')))
            ->first();

        if ($user === null
            || $user->status !== 'active'
            || ! Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $this->clientAccess->ensureUserMayUse($user, $client);

        return $user;
    }

    /**
     * Authenticate an active user and create a Sanctum token.
     *
     * @param  array{username: string, password: string}  $credentials
     * @return array{user: User, token: string}
     *
     * @throws AuthenticationException
     */
    public function login(array $credentials, string $client): array
    {
        return $this->issueToken($this->authenticate($credentials, $client), $client);
    }

    /** @return array{user: User, token: string} */
    public function issueToken(User $user, string $client): array
    {
        abort_unless($user->status === 'active', 403, 'This account is not active.');
        $this->clientAccess->ensureUserMayUse($user, $client);
        $expirationMinutes = SanctumTokenExpiration::minutes(config('sanctum.expiration'));
        $user->forceFill(['last_login' => now()])->save();

        return ['user' => $user->fresh(['role', 'profile']), 'token' => $user->createToken(
            'CDM Portal API Token ('.$client.')',
            [ClientPlatform::ability($client)],
            now()->addMinutes($expirationMinutes),
        )->plainTextToken];
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(User $user, ?string $plainTextToken): void
    {
        $accessToken = PersonalAccessToken::findToken($plainTextToken)
            ?? $user->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $user->tokens()->whereKey($accessToken->getKey())->delete();
        }
    }

    /**
     * Load the relations exposed by the authenticated-user resource.
     */
    public function currentUser(User $user): User
    {
        return $user->loadMissing(['role', 'profile']);
    }

    /**
     * Change the user's password and mark the initial password as changed.
     *
     * @param  array{current_password: string, password: string}  $data
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! Hash::check($data['current_password'], $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }

            $lockedUser->forceFill([
                'password' => Hash::make($data['password']),
                'is_first_login' => false,
            ])->save();
            $lockedUser->tokens()->delete();
            $lockedUser->loginOtpChallenges()
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            return $this->currentUser($lockedUser->fresh());
        });
    }
}

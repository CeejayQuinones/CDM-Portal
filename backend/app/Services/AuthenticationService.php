<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    /**
     * Authenticate an active user and create a Sanctum token.
     *
     * @param array{username: string, password: string} $credentials
     * @return array{user: User, token: string}
     *
     * @throws AuthenticationException
     */
    public function login(array $credentials): array
    {
        $user = User::query()
            ->with(['role', 'profile'])
            ->where('username', $credentials['username'])
            ->first();

        if ($user === null
            || $user->status !== 'active'
            || ! Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid username or password.');
        }

        $user->forceFill([
            'last_login' => now(),
        ])->save();

        return [
            'user' => $user->fresh(['role', 'profile']),
            'token' => $user->createToken('CDM Portal API Token')->plainTextToken,
        ];
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
     * @param array{current_password: string, password: string} $data
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, array $data): User
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'is_first_login' => false,
        ])->save();

        return $this->currentUser($user->fresh());
    }
}

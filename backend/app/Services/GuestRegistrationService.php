<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestRegistrationService
{
    /**
     * Create one active Guest account and its profile.
     *
     * @param array<string, mixed> $data
     */
    public function register(array $data): User
    {
        $guestRole = Role::query()->where('role_name', Role::GUEST)->first();

        if ($guestRole === null) {
            throw ValidationException::withMessages([
                'registration' => ['Guest registration is not available. Please contact support.'],
            ]);
        }

        return DB::transaction(function () use ($data, $guestRole): User {
            $user = User::query()->create([
                'username' => $data['username'],
                'password' => $data['password'],
                'role_id' => $guestRole->id,
                'status' => 'active',
                'is_first_login' => false,
            ]);

            $user->profile()->create([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'gender' => $data['gender'],
                'birth_date' => $data['birth_date'] ?? null,
                'email' => mb_strtolower($data['email']),
                'contact_number' => $data['contact_number'] ?? null,
                'address' => $data['address'] ?? null,
                'nationality' => $data['nationality'] ?? 'Filipino',
            ]);

            return $user->fresh(['role', 'profile']);
        });
    }
}

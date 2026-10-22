<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use LogicException;

class ProductionBootstrapAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['production', 'testing'])) {
            throw new LogicException('Production bootstrap accounts may only be created in production or isolated tests.');
        }

        $roles = DB::table('roles')->whereIn('role_name', ['Admin', 'Registrar Staff'])->pluck('id', 'role_name');
        if ($roles->count() !== 2) {
            throw new LogicException('Admin and Registrar Staff roles must exist before bootstrapping accounts.');
        }

        $counts = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('roles.role_name', ['Admin', 'Registrar Staff'])
            ->groupBy('roles.role_name')
            ->selectRaw('roles.role_name, COUNT(*) aggregate')
            ->pluck('aggregate', 'role_name');

        foreach (['Admin', 'Registrar Staff'] as $role) {
            if (($counts[$role] ?? 0) > 1) {
                throw new LogicException("Production already contains multiple {$role} accounts. No accounts were changed; review the inventory before cleanup.");
            }
        }

        $missingAdmin = ($counts['Admin'] ?? 0) === 0;
        $missingRegistrar = ($counts['Registrar Staff'] ?? 0) === 0;
        if (! $missingAdmin && ! $missingRegistrar) {
            $this->command?->info('One Admin and one Registrar Staff account already exist; bootstrap credentials were not applied.');

            return;
        }

        if (! config('production_bootstrap.enabled')) {
            throw new LogicException('Set PRODUCTION_BOOTSTRAP_ENABLED=true and provide the missing bootstrap account credentials.');
        }

        $admin = $missingAdmin ? $this->validated('admin') : null;
        $registrar = $missingRegistrar ? $this->validated('registrar') : null;
        $this->assertIdentityAvailable($admin);
        $this->assertIdentityAvailable($registrar);

        DB::transaction(function () use ($admin, $registrar, $roles): void {
            if ($admin) {
                $this->createAccount($admin, (int) $roles['Admin']);
            }
            if ($registrar) {
                [$userId, $profileId] = $this->createAccount($registrar, (int) $roles['Registrar Staff']);
                DB::table('registrar_staff')->insert([
                    'user_id' => $userId,
                    'user_profile_id' => $profileId,
                    'employee_number' => $registrar['employee_number'],
                    'position' => 'Registrar Staff',
                    'employment_status' => 'regular',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        $this->command?->info('Production bootstrap account creation completed. Passwords were not displayed.');
    }

    /** @return array<string, string> */
    private function validated(string $account): array
    {
        $data = array_filter(config("production_bootstrap.{$account}", []), fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $rules = [
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ];
        if ($account === 'registrar') {
            $rules['employee_number'] = ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'];
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new LogicException("Invalid or incomplete production {$account} bootstrap configuration: ".implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    /** @param array<string, string>|null $identity */
    private function assertIdentityAvailable(?array $identity): void
    {
        if (! $identity) {
            return;
        }
        if (DB::table('users')->where('username', $identity['username'])->exists()) {
            throw new LogicException("Bootstrap username {$identity['username']} already belongs to another account; no accounts were changed.");
        }
        if (DB::table('user_profiles')->where('email', $identity['email'])->exists()) {
            throw new LogicException("Bootstrap email {$identity['email']} already belongs to another account; no accounts were changed.");
        }
        if (isset($identity['employee_number']) && DB::table('registrar_staff')->where('employee_number', $identity['employee_number'])->exists()) {
            throw new LogicException("Bootstrap employee number {$identity['employee_number']} already exists; no accounts were changed.");
        }
    }

    /** @param array<string, string> $identity @return array{int, int} */
    private function createAccount(array $identity, int $roleId): array
    {
        $userId = DB::table('users')->insertGetId([
            'username' => $identity['username'],
            'password' => Hash::make($identity['password']),
            'role_id' => $roleId,
            'status' => 'active',
            'last_login' => null,
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profileId = DB::table('user_profiles')->insertGetId([
            'user_id' => $userId,
            'first_name' => $identity['first_name'],
            'last_name' => $identity['last_name'],
            'gender' => 'Prefer not to say',
            'email' => $identity['email'],
            'nationality' => 'Filipino',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$userId, $profileId];
    }
}

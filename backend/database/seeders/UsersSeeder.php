<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $roleNames = [
            'Guest',
            'Admin',
            'Registrar Staff',
            'Professor',
            'Student',
        ];

        $roleIds = DB::table('roles')
            ->whereIn('role_name', $roleNames)
            ->pluck('id', 'role_name');

        foreach ($roleNames as $roleName) {
            if (! $roleIds->has($roleName)) {
                throw new RuntimeException("The {$roleName} role could not be found. Run RolesSeeder before UsersSeeder.");
            }
        }

        $users = [
            ['username' => 'ken', 'password' => 'ken123!', 'role' => 'Guest'],
            ['username' => 'admin', 'password' => 'Admin123!', 'role' => 'Admin'],
            ['username' => 'registrar1', 'password' => 'Registrar123!', 'role' => 'Registrar Staff'],
            ['username' => 'professor1', 'password' => 'Professor123!', 'role' => 'Professor'],
            ['username' => 'student1', 'password' => 'Student123!', 'role' => 'Student'],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['username' => $user['username']],
                [
                    'password' => Hash::make($user['password']),
                    'role_id' => $roleIds[$user['role']],
                    'status' => 'active',
                    'last_login' => null,
                    'is_first_login' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}

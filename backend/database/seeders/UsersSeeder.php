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

        DB::table('users')->insert([
            [
                'username' => 'ken',
                'password' => Hash::make('ken123!'),
                'role_id' => $roleIds['Guest'],
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'admin',
                'password' => Hash::make('Admin123!'),
                'role_id' => $roleIds['Admin'],
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'registrar1',
                'password' => Hash::make('Registrar123!'),
                'role_id' => $roleIds['Registrar Staff'],
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'professor1',
                'password' => Hash::make('Professor123!'),
                'role_id' => $roleIds['Professor'],
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'student1',
                'password' => Hash::make('Student123!'),
                'role_id' => $roleIds['Student'],
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

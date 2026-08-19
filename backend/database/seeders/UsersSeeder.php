<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'username' => 'ken',
                'password' => Hash::make('ken123!'),
                'role_id' => 1,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'admin',
                'password' => Hash::make('Admin123!'),
                'role_id' => 2,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'registrar1',
                'password' => Hash::make('Registrar123!'),
                'role_id' => 3,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'professor1',
                'password' => Hash::make('Professor123!'),
                'role_id' => 4,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'student1',
                'password' => Hash::make('Student123!'),
                'role_id' => 5,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
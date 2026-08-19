<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'username' => 'admin',
                'password' => Hash::make('Admin123!'),
                'role_id' => 1,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRoleId = DB::table('roles')
            ->where('role_name', 'Admin')
            ->value('id');

        if ($adminRoleId === null) {
            throw new RuntimeException('The Admin role could not be found. Run RolesSeeder before AdminUserSeeder.');
        }

        DB::table('users')->updateOrInsert(
            ['username' => 'admin'],
            [
                'password' => Hash::make('Admin123!'),
                'role_id' => $adminRoleId,
                'status' => 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\DevelopmentOnlySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    use DevelopmentOnlySeeder;

    public function run(): void
    {
        $this->ensureDevelopmentEnvironment();
        $password = (string) env('DEVELOPMENT_ADMIN_PASSWORD');
        if (strlen($password) < 8) {
            throw new RuntimeException('Set DEVELOPMENT_ADMIN_PASSWORD to run the local AdminUserSeeder.');
        }
        $adminRoleId = DB::table('roles')
            ->where('role_name', 'Admin')
            ->value('id');

        if ($adminRoleId === null) {
            throw new RuntimeException('The Admin role could not be found. Run RolesSeeder before AdminUserSeeder.');
        }

        DB::table('users')->updateOrInsert(
            ['username' => 'admin'],
            [
                'password' => Hash::make($password),
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

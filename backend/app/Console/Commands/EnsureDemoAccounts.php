<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class EnsureDemoAccounts extends Command
{
    protected $signature = 'demo:ensure-accounts';

    protected $description = 'Ensure demo portal roles and login accounts exist';

    public function handle(): int
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('users')) {
            $this->error('roles/users tables missing. Run migrations first.');

            return self::FAILURE;
        }

        $roles = [
            1 => ['role_name' => 'Guest', 'description' => 'Public Portal User'],
            2 => ['role_name' => 'Admin', 'description' => 'System Administrator'],
            3 => ['role_name' => 'Registrar Staff', 'description' => 'Registrar Office Staff'],
            4 => ['role_name' => 'Professor', 'description' => 'Faculty Member'],
            5 => ['role_name' => 'Student', 'description' => 'Enrolled Student'],
        ];

        foreach ($roles as $id => $role) {
            DB::table('roles')->updateOrInsert(
                ['id' => $id],
                $role + ['created_at' => now(), 'updated_at' => now()],
            );
        }

        $roleIds = DB::table('roles')->pluck('id', 'role_name');

        $users = [
            ['username' => 'ken', 'password' => 'ken123!', 'role' => 'Guest'],
            ['username' => 'admin', 'password' => 'Admin123!', 'role' => 'Admin'],
            ['username' => 'registrar1', 'password' => 'Registrar123!', 'role' => 'Registrar Staff'],
            ['username' => 'professor1', 'password' => 'Professor123!', 'role' => 'Professor'],
            ['username' => 'student1', 'password' => 'Student123!', 'role' => 'Student'],
        ];

        foreach ($users as $user) {
            $existing = DB::table('users')->where('username', $user['username'])->first();
            $payload = [
                'password' => Hash::make($user['password']),
                'role_id' => $roleIds[$user['role']],
                'status' => 'active',
                'is_first_login' => true,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('users')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('users')->insert($payload + [
                    'username' => $user['username'],
                    'last_login' => null,
                    'created_at' => now(),
                ]);
            }

            $this->info("Ensured account: {$user['username']}");
        }

        return self::SUCCESS;
    }
}

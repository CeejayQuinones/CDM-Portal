<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Guest' => 'Public Portal User',
            'Admin' => 'System Administrator',
            'Registrar Staff' => 'Registrar Office Staff',
            'Professor' => 'Faculty Member',
            'Student' => 'Enrolled Student',
        ] as $role => $description) {
            DB::table('roles')->insertOrIgnore([
                'role_name' => $role,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

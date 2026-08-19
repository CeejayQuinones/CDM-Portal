<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegistrarStaffSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('registrar_staff')->insert([
            [
                'user_id' => 2,
                'user_profile_id' => 2,
                'employee_number' => 'REG-2026-001',
                'position' => 'Registrar Staff',
                'employment_status' => 'regular',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        
    }
}
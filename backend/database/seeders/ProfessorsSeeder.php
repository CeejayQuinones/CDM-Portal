<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProfessorsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('professors')->insert([
            [
                'user_id' => 3,
                'user_profile_id' => 3,
                'department_id' => 1,
                'employee_number' => 'FAC-2026-001',
                'position' => 'Instructor I',
                'specialization' => 'Software Development',
                'employment_status' => 'full_time',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
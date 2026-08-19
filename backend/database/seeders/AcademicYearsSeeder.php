<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicYearsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('academic_years')->insert([
            [
                'school_year' => '2025-2026',
                'start_date' => '2025-08-01',
                'end_date' => '2026-05-31',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'school_year' => '2026-2027',
                'start_date' => '2026-08-01',
                'end_date' => '2027-05-31',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'school_year' => '2027-2028',
                'start_date' => '2027-08-01',
                'end_date' => '2028-05-31',
                'status' => 'upcoming',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
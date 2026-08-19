<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GradingPeriodsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('grading_periods')->insert([
            [
                'period_name' => 'Prelim',
                'period_order' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'period_name' => 'Midterm',
                'period_order' => 2,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'period_name' => 'Final',
                'period_order' => 3,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
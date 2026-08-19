<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SemestersSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('semesters')->insert([
            [
                'semester_name' => 'First Semester',
                'semester_order' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'semester_name' => 'Second Semester',
                'semester_order' => 2,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'semester_name' => 'Summer',
                'semester_order' => 3,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
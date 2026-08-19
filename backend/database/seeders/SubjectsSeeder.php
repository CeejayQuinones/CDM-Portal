<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubjectsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('subjects')->insert([

            [
                'curriculum_id' => 1,
                'semester_id' => 1,
                'subject_code' => 'IT101',
                'subject_name' => 'Introduction to Computing',
                'year_level' => 1,
                'units' => 3,
                'lecture_hours' => 2,
                'laboratory_hours' => 3,
                'prerequisite_subject_id' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'curriculum_id' => 1,
                'semester_id' => 1,
                'subject_code' => 'IT102',
                'subject_name' => 'Computer Programming 1',
                'year_level' => 1,
                'units' => 3,
                'lecture_hours' => 2,
                'laboratory_hours' => 3,
                'prerequisite_subject_id' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'curriculum_id' => 1,
                'semester_id' => 2,
                'subject_code' => 'IT201',
                'subject_name' => 'Computer Programming 2',
                'year_level' => 1,
                'units' => 3,
                'lecture_hours' => 2,
                'laboratory_hours' => 3,
                'prerequisite_subject_id' => 2,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ]);
    }
}
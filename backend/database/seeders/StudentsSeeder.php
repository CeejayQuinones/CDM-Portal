<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('students')->insert([
            [
                'user_id' => 4,
                'user_profile_id' => 4,
                'course_id' => 1,
                'curriculum_id' => 1,
                'student_number' => '26-00001',
                'admission_date' => '2026-08-01',
                'student_status' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
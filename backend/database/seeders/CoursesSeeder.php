<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoursesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('courses')->insert([
            [
                'department_id' => 1, // ICS
                'course_code' => 'BSIT',
                'course_name' => 'Bachelor of Science in Information Technology',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_id' => 1, // ICS
                'course_code' => 'BSCS',
                'course_name' => 'Bachelor of Science in Computer Science',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_id' => 2, // IBE
                'course_code' => 'BSBA',
                'course_name' => 'Bachelor of Science in Business Administration',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_id' => 3, // ITE
                'course_code' => 'BSED',
                'course_name' => 'Bachelor of Secondary Education',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
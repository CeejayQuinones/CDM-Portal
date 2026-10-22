<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoursesSeeder extends Seeder
{
    public function run(): void
    {
        $departments = DB::table('departments')->whereIn('department_code', ['ICS', 'IBE', 'ITE'])->pluck('id', 'department_code');

        foreach ([
            [
                'department_code' => 'ICS',
                'course_code' => 'BSIT',
                'course_name' => 'Bachelor of Science in Information Technology',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_code' => 'ICS',
                'course_code' => 'BSCS',
                'course_name' => 'Bachelor of Science in Computer Science',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_code' => 'IBE',
                'course_code' => 'BSBA',
                'course_name' => 'Bachelor of Science in Business Administration',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_code' => 'ITE',
                'course_code' => 'BSED',
                'course_name' => 'Bachelor of Secondary Education',
                'years' => 4,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ] as $course) {
            $departmentCode = $course['department_code'];
            unset($course['department_code']);
            DB::table('courses')->insertOrIgnore([
                ...$course,
                'department_id' => $departments[$departmentCode],
            ]);
        }
    }
}

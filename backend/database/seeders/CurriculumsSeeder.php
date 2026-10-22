<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurriculumsSeeder extends Seeder
{
    public function run(): void
    {
        $courses = DB::table('courses')->whereIn('course_code', ['BSIT', 'BSCS', 'BSBA', 'BSED'])->pluck('id', 'course_code');

        foreach ([
            [
                'course_code' => 'BSIT',
                'curriculum_code' => 'BSIT-2026',
                'curriculum_name' => 'BSIT Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_code' => 'BSCS',
                'curriculum_code' => 'BSCS-2026',
                'curriculum_name' => 'BSCS Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_code' => 'BSBA',
                'curriculum_code' => 'BSBA-2026',
                'curriculum_name' => 'BSBA Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_code' => 'BSED',
                'curriculum_code' => 'BSED-2026',
                'curriculum_name' => 'BSED Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ] as $curriculum) {
            $courseCode = $curriculum['course_code'];
            unset($curriculum['course_code']);
            DB::table('curriculums')->insertOrIgnore([
                ...$curriculum,
                'course_id' => $courses[$courseCode],
            ]);
        }
    }
}

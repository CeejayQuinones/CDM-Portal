<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurriculumsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('curriculums')->insert([
            [
                'course_id' => 1,
                'curriculum_code' => 'BSIT-2026',
                'curriculum_name' => 'BSIT Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => 2,
                'curriculum_code' => 'BSCS-2026',
                'curriculum_name' => 'BSCS Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => 3,
                'curriculum_code' => 'BSBA-2026',
                'curriculum_name' => 'BSBA Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => 4,
                'curriculum_code' => 'BSED-2026',
                'curriculum_name' => 'BSED Curriculum 2026',
                'effective_year' => 2026,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
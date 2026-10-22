<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubjectsSeeder extends Seeder
{
    public function run(): void
    {
        $curriculumId = DB::table('curriculums')->where('curriculum_code', 'BSIT-2026')->value('id');
        $semesters = DB::table('semesters')->whereIn('semester_name', ['First Semester', 'Second Semester'])->pluck('id', 'semester_name');
        $subjects = [
            [
                'semester' => 'First Semester',
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
                'semester' => 'First Semester',
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
                'semester' => 'Second Semester',
                'subject_code' => 'IT201',
                'subject_name' => 'Computer Programming 2',
                'year_level' => 1,
                'units' => 3,
                'lecture_hours' => 2,
                'laboratory_hours' => 3,
                'prerequisite_code' => 'IT102',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ];

        foreach ($subjects as $subject) {
            $semester = $subject['semester'];
            $prerequisiteCode = $subject['prerequisite_code'] ?? null;
            unset($subject['semester'], $subject['prerequisite_code']);
            $subject['curriculum_id'] = $curriculumId;
            $subject['semester_id'] = $semesters[$semester];
            $subject['prerequisite_subject_id'] = $prerequisiteCode
                ? DB::table('subjects')->where('curriculum_id', $curriculumId)->where('subject_code', $prerequisiteCode)->value('id')
                : null;
            DB::table('subjects')->insertOrIgnore($subject);
        }
    }
}

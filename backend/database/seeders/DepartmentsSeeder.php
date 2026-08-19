<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('departments')->insert([
            [
                'department_code' => 'ICS',
                'department_name' => 'Institute of Computer Studies',
                'description' => 'Computer and Information Technology Programs',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_code' => 'IBE',
                'department_name' => 'Institute of Business Education',
                'description' => 'Business Programs',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'department_code' => 'ITE',
                'department_name' => 'Institute of Teacher Education',
                'description' => 'Education Programs',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
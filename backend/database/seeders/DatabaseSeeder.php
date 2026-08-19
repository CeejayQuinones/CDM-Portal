<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([

            // Core
            RolesSeeder::class,
            DepartmentsSeeder::class,
            AcademicYearsSeeder::class,
            SemestersSeeder::class,

            // Academic
            CoursesSeeder::class,
            CurriculumsSeeder::class,
            SubjectsSeeder::class,
            GradingPeriodsSeeder::class,

            // Accounts
            UsersSeeder::class,
            UserProfilesSeeder::class,

            // Personnel
            RegistrarStaffSeeder::class,
            ProfessorsSeeder::class,
            StudentsSeeder::class,
        ]);
    }
}
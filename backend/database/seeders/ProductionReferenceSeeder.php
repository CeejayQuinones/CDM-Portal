<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionReferenceSeeder extends Seeder
{
    /** Seed only non-account reference/configuration records. */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            DepartmentsSeeder::class,
            AcademicYearsSeeder::class,
            SemestersSeeder::class,
            CoursesSeeder::class,
            CurriculumsSeeder::class,
            SubjectsSeeder::class,
            GradingPeriodsSeeder::class,
            DocumentTypesSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductionReferenceSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call([
                AppointmentBlockedDateSeeder::class,
                UsersSeeder::class,
                UserProfilesSeeder::class,
                RegistrarStaffSeeder::class,
                StudentsSeeder::class,
                ProfessorsSeeder::class,
                StudentDocumentsSeeder::class,
                PhysicalRecordsSeeder::class,
                EventRoleTestingSeeder::class,
            ]);
        }
    }
}

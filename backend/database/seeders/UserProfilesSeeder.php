<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserProfilesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('user_profiles')->insert([

            // Guest
            [
                'user_id' => 1,
                'first_name' => 'Kenneth',
                'middle_name' => null,
                'last_name' => 'Gallaza',
                'suffix' => null,
                'gender' => 'Male',
                'birth_date' => null,
                'civil_status' => 'Single',
                'email' => 'ken@example.com',
                'contact_number' => null,
                'address' => 'Rodriguez, Rizal',
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Admin
            [
                'user_id' => 2,
                'first_name' => 'System',
                'middle_name' => null,
                'last_name' => 'Administrator',
                'suffix' => null,
                'gender' => 'Prefer not to say',
                'birth_date' => null,
                'civil_status' => null,
                'email' => 'admin@cdm.edu.ph',
                'contact_number' => null,
                'address' => null,
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Registrar
            [
                'user_id' => 3,
                'first_name' => 'Maria',
                'middle_name' => 'Santos',
                'last_name' => 'Cruz',
                'suffix' => null,
                'gender' => 'Female',
                'birth_date' => '1990-03-15',
                'civil_status' => 'Single',
                'email' => 'registrar@cdm.edu.ph',
                'contact_number' => '09171234567',
                'address' => 'Rodriguez, Rizal',
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Professor
            [
                'user_id' => 4,
                'first_name' => 'Juan',
                'middle_name' => 'Dela',
                'last_name' => 'Reyes',
                'suffix' => null,
                'gender' => 'Male',
                'birth_date' => '1988-07-20',
                'civil_status' => 'Married',
                'email' => 'professor@cdm.edu.ph',
                'contact_number' => '09181234567',
                'address' => 'Rodriguez, Rizal',
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Student
            [
                'user_id' => 5,
                'first_name' => 'John',
                'middle_name' => 'A.',
                'last_name' => 'Doe',
                'suffix' => null,
                'gender' => 'Male',
                'birth_date' => '2005-01-10',
                'civil_status' => 'Single',
                'email' => 'student@cdm.edu.ph',
                'contact_number' => '09191234567',
                'address' => 'Rodriguez, Rizal',
                'profile_photo' => null,
                'nationality' => 'Filipino',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ]);
    }
}
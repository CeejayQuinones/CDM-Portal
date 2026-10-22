<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\DevelopmentOnlySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class UserProfilesSeeder extends Seeder
{
    use DevelopmentOnlySeeder;

    public function run(): void
    {
        $this->ensureDevelopmentEnvironment();
        foreach ([

            // Guest
            [
                'username' => 'ken',
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
                'username' => 'admin',
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
                'username' => 'registrar1',
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
                'username' => 'professor1',
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
                'username' => 'student1',
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

        ] as $profile) {
            $username = $profile['username'];
            unset($profile['username']);
            $userId = DB::table('users')->where('username', $username)->value('id');
            if (! $userId) {
                throw new LogicException("Development user {$username} must exist before its profile is seeded.");
            }
            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $userId],
                $profile,
            );
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserProfilesSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            'admin' => [
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
            ],
            'registrar1' => [
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
            ],
            'professor1' => [
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
            ],
            'student1' => [
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
            ],
        ];

        foreach ($profiles as $username => $profile) {
            $userId = DB::table('users')->where('username', $username)->value('id');
            if ($userId === null) {
                throw new RuntimeException("The {$username} account could not be found. Run UsersSeeder before UserProfilesSeeder.");
            }

            $now = now();
            $payload = $profile + ['updated_at' => $now];
            $existing = DB::table('user_profiles')->where('user_id', $userId)->exists();
            if ($existing) {
                DB::table('user_profiles')->where('user_id', $userId)->update($payload);
            } else {
                DB::table('user_profiles')->insert($payload + [
                    'user_id' => $userId,
                    'created_at' => $now,
                ]);
            }
        }
    }
}

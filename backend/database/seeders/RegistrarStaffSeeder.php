<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegistrarStaffSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'registrar1')->firstOrFail();
        $profile = UserProfile::query()->where('user_id', $user->id)->firstOrFail();

        DB::table('registrar_staff')->updateOrInsert(
            ['employee_number' => 'REG-2026-001'],
            [
                'user_id' => $user->id,
                'user_profile_id' => $profile->id,
                'position' => 'Registrar Staff',
                'employment_status' => 'regular',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

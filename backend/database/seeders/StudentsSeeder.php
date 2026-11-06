<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\Concerns\DevelopmentOnlySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentsSeeder extends Seeder
{
    use DevelopmentOnlySeeder;

    public function run(): void
    {
        $this->ensureDevelopmentEnvironment();
        $user = User::query()->where('username', 'student1')->firstOrFail();
        $profile = UserProfile::query()->where('user_id', $user->id)->firstOrFail();

        DB::table('students')->updateOrInsert(
            ['student_number' => '26-00001'],
            [
                'user_id' => $user->id,
                'user_profile_id' => $profile->id,
                'course_id' => 1,
                'curriculum_id' => 1,
                'admission_date' => '2026-08-01',
                'year_level' => 1,
                'student_status' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

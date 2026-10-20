<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Repair the original seed-data links without changing roles or credentials. */
    public function up(): void
    {
        $student = DB::table('users')->where('username', 'student1')->value('id');
        $registrar = DB::table('users')->where('username', 'registrar1')->value('id');

        if ($student) {
            $profile = DB::table('user_profiles')->where('user_id', $student)->value('id');
            if ($profile) {
                DB::table('students')->where('student_number', '26-00001')->update([
                    'user_id' => $student,
                    'user_profile_id' => $profile,
                    'updated_at' => now(),
                ]);
            }
        }

        if ($registrar) {
            $profile = DB::table('user_profiles')->where('user_id', $registrar)->value('id');
            if ($profile) {
                DB::table('registrar_staff')->where('employee_number', 'REG-2026-001')->update([
                    'user_id' => $registrar,
                    'user_profile_id' => $profile,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // The previous mapping was invalid; intentionally do not restore it.
    }
};

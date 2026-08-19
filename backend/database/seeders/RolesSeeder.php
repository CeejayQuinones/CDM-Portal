<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->updateOrInsert(
            ['id' => 1],
            [
                'role_name' => 'Guest',
                'description' => 'Public Portal User',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('roles')->updateOrInsert(
            ['id' => 2],
            [
                'role_name' => 'Admin',
                'description' => 'System Administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('roles')->updateOrInsert(
            ['id' => 3],
            [
                'role_name' => 'Registrar Staff',
                'description' => 'Registrar Office Staff',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('roles')->updateOrInsert(
            ['id' => 4],
            [
                'role_name' => 'Professor',
                'description' => 'Faculty Member',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('roles')->updateOrInsert(
            ['id' => 5],
            [
                'role_name' => 'Student',
                'description' => 'Enrolled Student',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );


    }
}
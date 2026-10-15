<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('role_name', 'Guest')->value('id');
        if ($roleId === null) {
            return;
        }

        $userIds = DB::table('users')->where('role_id', $roleId)->pluck('id');
        if ($userIds->isNotEmpty() && Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $userIds)
                ->delete();
        }

        DB::table('users')->where('role_id', $roleId)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
    }

    public function down(): void
    {
        DB::table('roles')->updateOrInsert(
            ['role_name' => 'Guest'],
            [
                'description' => 'Public Portal User',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};

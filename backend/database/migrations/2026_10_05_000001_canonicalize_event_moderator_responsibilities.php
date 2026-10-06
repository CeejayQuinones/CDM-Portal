<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_role_assignments')->where('responsibility', 'moderator')->update(['responsibility' => 'registered_moderator']);
        DB::table('event_role_assignments')->where('responsibility', 'class_mayor')->update(['responsibility' => 'requested_moderator']);
    }

    public function down(): void
    {
        DB::table('event_role_assignments')->where('responsibility', 'registered_moderator')->update(['responsibility' => 'moderator']);
        DB::table('event_role_assignments')->where('responsibility', 'requested_moderator')->update(['responsibility' => 'class_mayor']);
    }
};

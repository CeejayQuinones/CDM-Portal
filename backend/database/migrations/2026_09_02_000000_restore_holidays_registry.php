<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            Schema::create('holidays', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->date('holiday_date')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('appointment_blocked_dates')) {
            DB::table('appointment_blocked_dates')
                ->where('type', 'holiday')
                ->orderBy('id')
                ->each(function (object $blockedDate): void {
                    DB::table('holidays')->updateOrInsert(
                        ['holiday_date' => $blockedDate->blocked_date],
                        [
                            'name' => $blockedDate->reason ?: 'Holiday',
                            'is_active' => $blockedDate->is_active,
                            'created_at' => $blockedDate->created_at ?? now(),
                            'updated_at' => $blockedDate->updated_at ?? now(),
                        ],
                    );
                });
        }
    }

    public function down(): void
    {
        // The table predates appointment_blocked_dates. Keep it on rollback to avoid data loss.
    }
};

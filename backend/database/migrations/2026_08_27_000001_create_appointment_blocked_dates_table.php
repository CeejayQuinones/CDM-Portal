<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_blocked_dates', function (Blueprint $table): void {
            $table->id();
            $table->date('blocked_date');
            $table->string('type', 50);
            $table->string('reason');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['blocked_date', 'is_active']);
        });

        if (Schema::hasTable('holidays')) {
            foreach (DB::table('holidays')->orderBy('id')->get() as $holiday) {
                DB::table('appointment_blocked_dates')->insert([
                    'blocked_date' => $holiday->holiday_date,
                    'type' => 'holiday',
                    'reason' => $holiday->name,
                    'is_active' => $holiday->is_active,
                    'created_by' => null,
                    'created_at' => $holiday->created_at,
                    'updated_at' => $holiday->updated_at,
                ]);
            }

            Schema::drop('holidays');
        }
    }

    public function down(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('holiday_date')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (DB::table('appointment_blocked_dates')->where('type', 'holiday')->orderBy('id')->get() as $holiday) {
            DB::table('holidays')->updateOrInsert(
                ['holiday_date' => $holiday->blocked_date],
                [
                    'name' => $holiday->reason,
                    'is_active' => $holiday->is_active,
                    'created_at' => $holiday->created_at,
                    'updated_at' => $holiday->updated_at,
                ]
            );
        }

        Schema::dropIfExists('appointment_blocked_dates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointment_blocked_dates')) {
            return;
        }

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
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_blocked_dates');
    }
};

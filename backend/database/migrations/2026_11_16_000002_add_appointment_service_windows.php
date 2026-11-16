<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('service_window', 20)->nullable()->after('appointment_time');
            $table->index(['appointment_date', 'service_window', 'status'], 'appointments_window_date_status_index');
        });

        Schema::create('appointment_window_capacities', function (Blueprint $table): void {
            $table->id();
            $table->date('appointment_date');
            $table->string('service_window', 20);
            $table->unsignedSmallInteger('capacity')->default(100);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['appointment_date', 'service_window'], 'appointment_window_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_window_capacities');
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex('appointments_window_date_status_index');
            $table->dropColumn('service_window');
        });
    }
};

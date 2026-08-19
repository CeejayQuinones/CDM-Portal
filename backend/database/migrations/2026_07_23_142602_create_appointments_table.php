<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('appointments', function (Blueprint $table) {

        $table->id();

        // Student
        $table->foreignId('student_id')
              ->constrained('students')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Related Document Request (Optional)
        $table->foreignId('document_request_id')
              ->nullable()
              ->constrained('document_requests')
              ->nullOnDelete()
              ->cascadeOnUpdate();

        // Registrar Staff Assigned
        $table->foreignId('registrar_staff_id')
              ->nullable()
              ->constrained('registrar_staff')
              ->nullOnDelete()
              ->cascadeOnUpdate();

        // Appointment Details
        $table->date('appointment_date');
        $table->time('appointment_time');

        // Purpose
        $table->string('purpose');

        // Appointment Status
        $table->enum('status', [
            'pending',
            'confirmed',
            'completed',
            'cancelled',
            'no_show'
        ])->default('pending');

        // Additional Notes
        $table->text('remarks')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};

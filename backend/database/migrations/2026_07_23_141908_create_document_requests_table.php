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
    Schema::create('document_requests', function (Blueprint $table) {

        $table->id();

        // Student Requesting
        $table->foreignId('student_id')
              ->constrained('students')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Requested Document
        $table->foreignId('document_type_id')
              ->constrained('document_types')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Registrar Staff Handling the Request
        $table->foreignId('registrar_staff_id')
              ->nullable()
              ->constrained('registrar_staff')
              ->nullOnDelete()
              ->cascadeOnUpdate();

        // Quantity Requested
        $table->unsignedInteger('quantity')->default(1);

        // Total Fee
        $table->decimal('total_fee', 8, 2)->default(0);

        // Purpose
        $table->string('purpose')->nullable();

        // Request Status
        $table->enum('status', [
            'pending',
            'processing',
            'ready_for_release',
            'released',
            'cancelled',
            'rejected'
        ])->default('pending');

        // Important Dates
        $table->date('request_date');
        $table->date('release_date')->nullable();

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
        Schema::dropIfExists('document_requests');
    }
};

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
    Schema::create('student_documents', function (Blueprint $table) {

        $table->id();

        // Student
        $table->foreignId('student_id')
              ->constrained('students')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Document Type
        $table->foreignId('document_type_id')
              ->constrained('document_types')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Physical Storage Location
        $table->foreignId('document_storage_location_id')
              ->nullable()
              ->constrained('document_storage_locations')
              ->nullOnDelete()
              ->cascadeOnUpdate();

        // Digital Copy (Optional)
        $table->string('file_path')->nullable();

        // Verification Status
        $table->enum('verification_status', [
            'pending',
            'verified',
            'rejected'
        ])->default('pending');

        // Registrar Notes
        $table->text('remarks')->nullable();

        // Date Submitted
        $table->date('submitted_date')->nullable();

        $table->timestamps();

        // Prevent duplicate document types per student
        $table->unique([
            'student_id',
            'document_type_id'
        ]);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};

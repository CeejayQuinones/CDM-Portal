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
    Schema::create('enrollments', function (Blueprint $table) {

        $table->id();

        // Student
        $table->foreignId('student_id')
              ->constrained('students')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Section
        $table->foreignId('section_id')
              ->constrained('sections')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Academic Year
        $table->foreignId('academic_year_id')
              ->constrained('academic_years')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Semester
        $table->foreignId('semester_id')
              ->constrained('semesters')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Enrollment Date
        $table->date('enrollment_date');

        // Enrollment Status
        $table->enum('status', [
            'pending',
            'enrolled',
            'cancelled',
            'completed'
        ])->default('pending');

        $table->timestamps();

        // Prevent duplicate enrollment
        $table->unique([
            'student_id',
            'academic_year_id',
            'semester_id'
        ]);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};

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
    Schema::create('grades', function (Blueprint $table) {

        $table->id();

        // Student Subject Enrollment
        $table->foreignId('enrollment_subject_id')
              ->constrained('enrollment_subjects')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Grading Period
        $table->foreignId('grading_period_id')
              ->constrained('grading_periods')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Professor who submitted the grade
        $table->foreignId('professor_id')
              ->constrained('professors')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Grade
        $table->decimal('grade', 5, 2)->nullable();

        // Remarks
        $table->enum('remarks', [
            'Passed',
            'Failed',
            'Incomplete',
            'Dropped',
            'In Progress'
        ])->default('In Progress');

        // Submission Status
        $table->enum('status', [
            'draft',
            'submitted',
            'approved'
        ])->default('draft');

        // Date Submitted
        $table->timestamp('submitted_at')->nullable();

        $table->timestamps();

        // One grade per grading period per enrolled subject
        $table->unique([
            'enrollment_subject_id',
            'grading_period_id'
        ]);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};

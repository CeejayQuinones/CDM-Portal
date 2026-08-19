<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enrollment_subjects', function (Blueprint $table) {

            $table->id();

            // Enrollment Record
            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Subject
            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Professor teaching this subject
            $table->foreignId('professor_id')
                ->nullable()
                ->constrained('professors')
                ->nullOnDelete();

            // Subject Status
            $table->enum('subject_status', [
                'enrolled',
                'dropped',
                'completed'
            ])->default('enrolled');

            // Final Grade
            $table->decimal('final_grade', 5, 2)->nullable();

            // Remarks
            $table->enum('remarks', [
                'Passed',
                'Failed',
                'Incomplete',
                'Dropped',
                'In Progress'
            ])->default('In Progress');

            $table->timestamps();

            // Prevent duplicate subjects in one enrollment
            $table->unique([
                'enrollment_id',
                'subject_id'
            ]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_subjects');
    }
};

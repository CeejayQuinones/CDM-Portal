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
        Schema::create('curriculums', function (Blueprint $table) {

            $table->id();

            // Course Relationship
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Curriculum Information
            $table->string('curriculum_code', 30)->unique();

            // Example:
            // BSIT-2026

            $table->string('curriculum_name', 150);

            // Example:
            // BSIT Curriculum 2026

            $table->year('effective_year');

            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculums');
    }
};

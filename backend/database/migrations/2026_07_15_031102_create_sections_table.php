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
        Schema::create('sections', function (Blueprint $table) {

            $table->id();

            // Academic Information
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('semester_id')
                ->constrained('semesters')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Section Information
            $table->string('section_name', 20);

            // Example:
            // BSIT-1A
            // BSIT-2B

            $table->unsignedTinyInteger('year_level');

            // Adviser
            $table->foreignId('adviser_id')
                ->nullable()
                ->constrained('professors')
                ->nullOnDelete();

            // Capacity
            $table->unsignedSmallInteger('capacity')->default(40);

            // Status
            $table->enum('status', [
                'open',
                'closed'
            ])->default('open');

            $table->timestamps();

            // Prevent duplicate sections in the same school year and semester
            $table->unique(
                ['course_id', 'academic_year_id', 'semester_id', 'section_name'],
                'sections_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};

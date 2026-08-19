<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {

            $table->id();

            // Curriculum
            $table->foreignId('curriculum_id')
                  ->constrained('curriculums')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete();

            // Semester
            $table->foreignId('semester_id')
                  ->constrained('semesters')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            // Subject Information
            $table->string('subject_code', 20);
            $table->string('subject_name', 150);

            $table->unsignedTinyInteger('year_level');

            $table->decimal('units', 4, 1);

            $table->unsignedTinyInteger('lecture_hours')->default(0);
            $table->unsignedTinyInteger('laboratory_hours')->default(0);

            // Prerequisite
            $table->foreignId('prerequisite_subject_id')
                  ->nullable()
                  ->constrained('subjects')
                  ->nullOnDelete();

            // Status
            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');

            $table->timestamps();

            $table->unique(
                ['curriculum_id', 'subject_code'],
                'curriculum_subject_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
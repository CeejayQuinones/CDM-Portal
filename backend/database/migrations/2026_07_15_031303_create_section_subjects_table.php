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
    Schema::create('section_subjects', function (Blueprint $table) {

        $table->id();

        // Section
        $table->foreignId('section_id')
              ->constrained('sections')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Subject
        $table->foreignId('subject_id')
              ->constrained('subjects')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Professor handling the subject
        $table->foreignId('professor_id')
              ->nullable()
              ->constrained('professors')
              ->nullOnDelete();

        // Schedule
        $table->string('day', 20)->nullable();

        $table->time('start_time')->nullable();

        $table->time('end_time')->nullable();

        $table->string('room', 50)->nullable();

        $table->timestamps();

        $table->unique([
            'section_id',
            'subject_id'
        ]);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_subjects');
    }
};

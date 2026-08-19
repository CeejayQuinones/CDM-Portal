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
    Schema::create('students', function (Blueprint $table) {

        $table->id();

        // Login Account
        $table->foreignId('user_id')
              ->unique()
              ->constrained('users')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Personal Profile
        $table->foreignId('user_profile_id')
              ->unique()
              ->constrained('user_profiles')
              ->cascadeOnUpdate()
              ->cascadeOnDelete();

        // Academic Information
        $table->foreignId('course_id')
              ->constrained('courses');

        $table->foreignId('curriculum_id')
              ->constrained('curriculums');

        // Student Information
        $table->string('student_number',20)->unique()->nullable();

        $table->date('admission_date');

        $table->enum('student_status',[
            'regular',
            'irregular',
            'graduated',
            'transferred',
            'dropped',
            'leave_of_absence'
        ])->default('regular');

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

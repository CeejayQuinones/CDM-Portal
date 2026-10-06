<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_sheets', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
            $table->decimal('midterm_weight', 5, 2)->default(40);
            $table->decimal('finals_weight', 5, 2)->default(60);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('return_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('grade_submission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_sheet_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->unsignedInteger('sheet_version');
            $table->decimal('midterm_weight', 5, 2);
            $table->decimal('finals_weight', 5, 2);
            $table->json('configuration');
            $table->char('checksum', 64);
            $table->timestamps();
            $table->unique(['grade_sheet_id', 'attempt_number']);
        });

        Schema::create('grade_submission_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_submission_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('student_number', 20);
            $table->string('student_name', 255);
            $table->decimal('midterm_grade', 5, 2);
            $table->decimal('finals_grade', 5, 2);
            $table->decimal('final_grade', 5, 2);
            $table->decimal('grade_point', 5, 2)->nullable();
            $table->string('remarks', 40)->nullable();
            $table->json('breakdown');
            $table->timestamps();
            $table->unique(['grade_submission_attempt_id', 'enrollment_subject_id'], 'grade_submission_student_unique');
        });

        Schema::create('grade_release_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->dateTime('release_at');
            $table->enum('status', ['scheduled', 'released'])->default('scheduled');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'release_at']);
        });

        Schema::create('grade_release_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_release_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_sheet_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_release_items');
        Schema::dropIfExists('grade_release_schedules');
        Schema::dropIfExists('grade_submission_students');
        Schema::dropIfExists('grade_submission_attempts');
        Schema::table('grade_sheets', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['returned_by']);
            $table->dropForeign(['published_by']);
            $table->dropColumn(['midterm_weight', 'finals_weight', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'returned_at', 'returned_by', 'return_reason', 'published_at', 'published_by']);
            $table->enum('status', ['draft'])->default('draft')->change();
        });
    }
};

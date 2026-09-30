<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('semester_id')->constrained()->restrictOnDelete();
            $t->dateTime('opens_at');
            $t->dateTime('closes_at');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->json('document_requirements');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['academic_year_id', 'semester_id']);
            $t->index(['enabled', 'opens_at', 'closes_at']);
        });
        Schema::create('enrollment_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('period_id')->constrained('enrollment_periods')->restrictOnDelete();
            $t->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('semester_id')->constrained()->restrictOnDelete();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('curriculum_id')->constrained('curriculums')->restrictOnDelete();
            $t->unsignedTinyInteger('year_level');
            $t->string('classification', 20);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_notes')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'academic_year_id', 'semester_id'], 'enrollment_application_student_term_unique');
            $t->index(['period_id', 'status']);
            $t->index(['classification', 'status']);
        });
        Schema::create('enrollment_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained('enrollment_applications')->restrictOnDelete();
            $t->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_document_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('file_path')->nullable();
            $t->timestamps();
            $t->unique(['application_id', 'document_type_id']);
        });
        Schema::create('enrollment_workflow_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_uuid')->unique();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_role');
            $t->string('action');
            $t->string('subject_type', 20);
            $t->unsignedBigInteger('subject_id');
            $t->json('metadata');
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id', 'created_at'], 'enrollment_event_subject_index');
        });
    }

    public function down(): void
    {
        foreach (['enrollment_workflow_events', 'enrollment_documents', 'enrollment_applications', 'enrollment_periods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

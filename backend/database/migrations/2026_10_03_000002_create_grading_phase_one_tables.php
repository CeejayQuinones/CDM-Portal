<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_period_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->dateTime('midterm_opens_at');
            $table->dateTime('midterm_deadline');
            $table->dateTime('finals_opens_at');
            $table->dateTime('finals_deadline');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['academic_year_id', 'semester_id'], 'grade_period_schedule_term_unique');
        });

        Schema::create('grade_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_subject_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('professor_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('grade_category_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_sheet_id')->constrained()->cascadeOnDelete();
            $table->enum('period', ['midterm', 'finals']);
            $table->string('category', 30);
            $table->decimal('weight_percentage', 5, 2);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['grade_sheet_id', 'period', 'category'], 'grade_weight_identity_unique');
        });

        Schema::create('grade_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_sheet_id')->constrained()->cascadeOnDelete();
            $table->enum('period', ['midterm', 'finals']);
            $table->string('label', 80);
            $table->string('category', 30);
            $table->decimal('max_score', 8, 2);
            $table->unsignedSmallInteger('display_order');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['grade_sheet_id', 'period', 'display_order'], 'grade_assessment_order_unique');
        });

        Schema::create('grade_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_subject_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['grade_assessment_id', 'enrollment_subject_id'], 'grade_score_identity_unique');
        });

        Schema::create('grade_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('grade_sheet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_period_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['grade_sheet_id', 'created_at']);
            $table->index(['grade_period_schedule_id', 'created_at'], 'grade_period_audit_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_audit_events');
        Schema::dropIfExists('grade_scores');
        Schema::dropIfExists('grade_assessments');
        Schema::dropIfExists('grade_category_weights');
        Schema::dropIfExists('grade_sheets');
        Schema::dropIfExists('grade_period_schedules');
    }
};

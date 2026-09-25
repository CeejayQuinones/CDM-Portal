<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admission_workflow_events')) {
            Schema::create('admission_workflow_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_uuid');
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('actor_role', 40);
                $table->foreignId('applicant_id')->nullable()->constrained('admission_applicants')->restrictOnDelete();
                $table->string('subject_type', 40);
                $table->string('subject_id', 64);
                $table->string('action', 80);
                $table->json('metadata');
                $table->timestamp('created_at');

            });
        }
        // Resume the additive first-table creation if MariaDB rejected a long generated index name.
        if (! Schema::hasIndex('admission_workflow_events', 'admission_workflow_subject_idx')) {
            Schema::table('admission_workflow_events', fn (Blueprint $table) => $table->index(['subject_type', 'subject_id', 'created_at'], 'admission_workflow_subject_idx'));
        }
        if (! Schema::hasIndex('admission_workflow_events', 'admission_workflow_event_uuid_unique')) {
            Schema::table('admission_workflow_events', fn (Blueprint $table) => $table->unique('event_uuid', 'admission_workflow_event_uuid_unique'));
        }
        if (! Schema::hasTable('admission_program_settings')) {
            Schema::create('admission_program_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->unique()->constrained()->restrictOnDelete();
                $table->string('status', 16)->default('inactive');
                $table->boolean('is_recommendable')->default(false);
                $table->string('program_type', 20)->default('degree');
                $table->text('description')->nullable();
                $table->string('duration')->nullable();
                $table->json('subjects')->nullable();
                $table->json('career_paths')->nullable();
                $table->json('recommendation_profile')->nullable();
                $table->unsignedInteger('display_order')->default(0);
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('admission_exam_questions')) {
            Schema::create('admission_exam_questions', function (Blueprint $table) {
                $table->id();
                $table->string('question_code', 64)->unique();
                $table->string('topic', 40);
                $table->text('question_text');
                foreach (['a', 'b', 'c', 'd'] as $option) {
                    $table->text('option_'.$option);
                }
                $table->char('correct_answer', 1);
                $table->string('difficulty', 10);
                $table->string('status', 16)->default('draft');
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['status', 'topic']);
            });
        }
        Schema::create('admission_exam_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('applicant_id')->constrained('admission_applicants')->restrictOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('status', 16)->default('active');
            $table->string('bank_version', 64);
            $table->json('policy_snapshot');
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('deadline_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['applicant_id', 'attempt_number']);
            $table->index(['status', 'deadline_at']);
        });
        Schema::create('admission_exam_session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('admission_exam_sessions')->restrictOnDelete();
            $table->foreignId('question_id')->constrained('admission_exam_questions')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('topic', 40);
            $table->unsignedInteger('question_version');
            $table->text('question_text');
            $table->json('options');
            $table->char('correct_answer', 1);
            $table->timestamp('created_at');
            $table->unique(['session_id', 'position']);
            $table->unique(['session_id', 'question_id']);
        });
        Schema::create('admission_exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_question_id')->unique()->constrained('admission_exam_session_questions')->restrictOnDelete();
            $table->char('selected_option', 1)->nullable();
            $table->unsignedInteger('accepted_revision')->default(0);
            $table->timestamp('saved_at')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamps();
        });
        Schema::create('admission_exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->unique()->constrained('admission_exam_sessions')->restrictOnDelete();
            $table->unsignedInteger('raw_correct_count');
            $table->unsignedInteger('question_count');
            $table->decimal('system_percentage', 5, 2);
            $table->boolean('system_passed');
            $table->json('category_scores');
            $table->json('category_maximums');
            $table->unsignedInteger('time_spent_seconds');
            $table->timestamp('finalized_at');
            $table->string('finalization_cause', 16);
            $table->unsignedTinyInteger('official_score')->nullable();
            $table->string('official_status', 16)->default('pending');
            $table->boolean('registrar_pass')->default(false);
            $table->text('internal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['official_status', 'published_at']);
        });
        Schema::create('admission_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('admission_applicants')->restrictOnDelete();
            $table->foreignId('result_id')->constrained('admission_exam_results')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->uuid('operation_key')->unique();
            $table->unsignedInteger('application_version');
            $table->unsignedInteger('result_version');
            $table->json('before');
            $table->json('after');
            $table->text('internal_reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['applicant_id', 'created_at']);
        });
        Schema::create('admission_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->constrained('admission_exam_results')->restrictOnDelete();
            $table->unsignedInteger('result_version');
            $table->string('input_fingerprint', 64);
            $table->json('interests');
            $table->json('catalog_snapshot');
            $table->string('matcher_version', 20);
            $table->string('model_name')->nullable();
            $table->string('generation_status', 24);
            $table->string('ai_status', 24);
            $table->json('ranked_programs');
            $table->json('evidence');
            $table->json('explanations');
            $table->foreignId('top_course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['result_id', 'input_fingerprint'], 'admission_recommendation_fingerprint_unique');
        });
    }

    public function down(): void
    {
        foreach (['admission_recommendations', 'admission_decisions', 'admission_exam_results', 'admission_exam_answers',
            'admission_exam_session_questions', 'admission_exam_sessions', 'admission_exam_questions', 'admission_program_settings', 'admission_workflow_events'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};

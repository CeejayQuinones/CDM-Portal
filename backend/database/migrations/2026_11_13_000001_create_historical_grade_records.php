<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_grade_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained('curriculums')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('subject_code', 50);
            $table->string('subject_name', 200);
            $table->decimal('credited_units', 6, 2);
            $table->string('final_grade', 40);
            $table->string('official_result', 20);
            $table->string('source_type', 20);
            $table->string('source_reference', 255);
            $table->string('verification_status', 20)->default('verified');
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id', 'semester_id', 'subject_id'], 'historical_grade_identity_unique');
            $table->index(
                ['student_id', 'verification_status', 'official_result'],
                'hgr_student_status_result_idx'
            );
        });

        Schema::create('historical_grade_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_grade_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('historical_grade_import_previews', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('rows');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['historical_grade_records', 'historical_grade_events', 'historical_grade_import_previews'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Historical grade records, audit events, or import previews exist; approve a separate data-preserving rollback plan.');
            }
        }

        Schema::dropIfExists('historical_grade_import_previews');
        Schema::dropIfExists('historical_grade_events');
        Schema::dropIfExists('historical_grade_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_applicants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cycle_id')->constrained('admission_cycles')->restrictOnDelete();
            $table->string('applicant_number', 40)->unique();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'accepted', 'rejected', 'withdrawn', 'expired', 'converted'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('preferred_course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->foreignId('accepted_course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->foreignId('accepted_curriculum_id')->nullable()->constrained('curriculums')->restrictOnDelete();
            $table->foreignId('converted_student_id')->nullable()->unique()->constrained('students')->restrictOnDelete();
            $table->json('profile_snapshot')->nullable();
            $table->unsignedInteger('snapshot_version')->nullable();
            $table->string('verified_contact_email')->nullable();
            $table->timestamp('contact_verified_at')->nullable();
            foreach (['submitted_at', 'accepted_at', 'rejected_at', 'withdrawn_at', 'expired_at', 'converted_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->string('source_system', 50)->nullable();
            $table->string('source_applicant_id', 100)->nullable();
            $table->string('legacy_applicant_number')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'cycle_id']);
            $table->unique(['source_system', 'source_applicant_id'], 'admission_applicants_source_unique');
            $table->index(['cycle_id', 'status', 'submitted_at'], 'admission_applicants_cycle_status_index');
            $table->index(['user_id', 'created_at']);
            $table->index('legacy_applicant_number');
            $table->index('preferred_course_id');
            $table->index('accepted_course_id');
            $table->index('accepted_curriculum_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_applicants');
    }
};

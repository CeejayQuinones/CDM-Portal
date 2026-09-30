<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->index(['academic_year_id', 'semester_id', 'status'], 'sections_term_status_index');
        });
        Schema::table('section_subjects', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->index(['day', 'start_time', 'end_time'], 'section_subjects_overlap_index');
        });
        Schema::table('enrollment_applications', function (Blueprint $t) {
            $t->foreignId('section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $t->foreignId('enrollment_id')->nullable()->unique()->constrained('enrollments')->restrictOnDelete();
            $t->timestamp('load_reviewed_at')->nullable();
            $t->foreignId('load_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('finalized_at')->nullable();
        });
        Schema::create('enrollment_application_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained('enrollment_applications')->restrictOnDelete();
            $t->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['application_id', 'subject_id'], 'enrollment_application_subject_unique');
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('enrollment_application_subjects');
        Schema::table('enrollment_applications', function (Blueprint $t) {
            $t->dropConstrainedForeignId('section_id');
            $t->dropConstrainedForeignId('enrollment_id');
            $t->dropConstrainedForeignId('load_reviewed_by');
            $t->dropColumn(['load_reviewed_at', 'finalized_at']);
        });
        Schema::table('section_subjects', function (Blueprint $t) {
            $t->dropIndex('section_subjects_overlap_index');
            $t->dropColumn('version');
        });
        Schema::table('sections', function (Blueprint $t) {
            $t->dropIndex('sections_term_status_index');
            $t->dropColumn('version');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('parent_event_id')->nullable()->after('id')->constrained('events')->restrictOnDelete();
            $table->index(['parent_event_id', 'starts_at'], 'events_parent_schedule_index');
        });

        Schema::table('event_attendances', function (Blueprint $table): void {
            $table->foreignId('course_id_at_attendance')->nullable()->after('student_id')->constrained('courses')->restrictOnDelete();
            $table->unsignedTinyInteger('year_level_at_attendance')->nullable()->after('course_id_at_attendance');
            $table->foreignId('section_id_at_attendance')->nullable()->after('year_level_at_attendance')->constrained('sections')->restrictOnDelete();
            $table->index(['event_id', 'course_id_at_attendance', 'year_level_at_attendance'], 'event_attendance_report_academic_index');
            $table->index(['event_id', 'section_id_at_attendance'], 'event_attendance_report_section_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendances', function (Blueprint $table): void {
            $table->dropIndex('event_attendance_report_academic_index');
            $table->dropIndex('event_attendance_report_section_index');
            $table->dropConstrainedForeignId('section_id_at_attendance');
            $table->dropColumn('year_level_at_attendance');
            $table->dropConstrainedForeignId('course_id_at_attendance');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_parent_schedule_index');
            $table->dropConstrainedForeignId('parent_event_id');
        });
    }
};

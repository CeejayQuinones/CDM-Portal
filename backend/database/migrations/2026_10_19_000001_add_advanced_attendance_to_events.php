<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_event_id');
            $table->string('attendance_mode', 24)->default('check_in_only')->after('status');
            $table->unsignedSmallInteger('late_threshold_minutes')->default(15)->after('attendance_mode');
            $table->unsignedSmallInteger('minimum_participation_minutes')->nullable()->after('late_threshold_minutes');
            $table->json('attendance_methods')->nullable()->after('minimum_participation_minutes');
            $table->index(['parent_event_id', 'sort_order', 'starts_at'], 'events_parent_order_index');
        });

        Schema::table('event_attendance_sessions', function (Blueprint $table): void {
            $table->char('static_qr_token_fingerprint', 64)->nullable()->after('qr_expires_at');
            $table->unsignedInteger('static_token_version')->default(1)->after('static_qr_token_fingerprint');
        });

        Schema::table('event_attendances', function (Blueprint $table): void {
            $table->unsignedInteger('participation_minutes')->nullable()->after('checked_out_at');
            $table->string('completion_status', 16)->default('completed')->after('participation_minutes');
            $table->index(['event_id', 'completion_status'], 'event_attendance_completion_index');
        });

        Schema::create('event_attendance_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('attendance_session_id')->constrained('event_attendance_sessions')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('action', 16);
            $table->string('status', 16)->default('pending');
            $table->string('active_request_key', 96)->nullable()->unique();
            $table->char('token_fingerprint', 64);
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'status', 'requested_at'], 'event_verification_status_index');
            $table->index(['student_id', 'requested_at'], 'event_verification_student_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendance_verifications');

        Schema::table('event_attendances', function (Blueprint $table): void {
            $table->dropIndex('event_attendance_completion_index');
            $table->dropColumn(['participation_minutes', 'completion_status']);
        });

        Schema::table('event_attendance_sessions', function (Blueprint $table): void {
            $table->dropColumn(['static_qr_token_fingerprint', 'static_token_version']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_parent_order_index');
            $table->dropColumn(['sort_order', 'attendance_mode', 'late_threshold_minutes', 'minimum_participation_minutes', 'attendance_methods']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('status', 12)->default('open');
            $table->char('qr_token_fingerprint', 64)->nullable();
            $table->dateTime('qr_expires_at')->nullable();
            $table->unsignedInteger('token_version')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'opened_at']);
        });

        Schema::create('event_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('attendance_session_id')->constrained('event_attendance_sessions')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('status', 12);
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('source', 12);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['event_id', 'student_id'], 'event_attendance_student_unique');
            $table->index(['event_id', 'status']);
            $table->index(['attendance_session_id', 'checked_in_at'], 'event_attendance_session_time_index');
        });

        Schema::create('event_attendance_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('attendance_session_id')->nullable()->constrained('event_attendance_sessions')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->restrictOnDelete();
            $table->dateTime('scanned_at');
            $table->string('result', 24);
            $table->char('token_fingerprint', 64);
            $table->string('client_platform', 12);
            $table->json('metadata');
            $table->index(['event_id', 'scanned_at'], 'event_scan_event_time_index');
            $table->index(['student_id', 'result', 'scanned_at'], 'event_scan_student_result_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendance_scans');
        Schema::dropIfExists('event_attendances');
        Schema::dropIfExists('event_attendance_sessions');
    }
};

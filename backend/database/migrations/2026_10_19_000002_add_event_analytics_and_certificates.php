<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('certificates_enabled')->default(false)->after('attendance_methods');
            $table->unsignedInteger('certificate_minimum_minutes')->nullable()->after('certificates_enabled');
        });

        Schema::table('event_attendances', function (Blueprint $table): void {
            $table->string('check_in_method', 32)->nullable()->after('completion_status');
            $table->string('check_out_method', 32)->nullable()->after('check_in_method');
            $table->index(['event_id', 'check_in_method'], 'event_attendance_method_index');
        });

        Schema::create('event_certificate_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('required_event_id')->constrained('events')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'required_event_id'], 'event_certificate_requirement_unique');
        });

        Schema::create('event_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->char('verification_code', 64)->unique();
            $table->string('status', 12)->default('issued');
            $table->string('active_issue_key')->nullable()->unique();
            $table->timestamp('issued_at');
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'student_id', 'version'], 'event_certificate_version_unique');
            $table->index(['student_id', 'status', 'issued_at'], 'event_certificate_student_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_certificates');
        Schema::dropIfExists('event_certificate_requirements');
        Schema::table('event_attendances', function (Blueprint $table): void {
            $table->dropIndex('event_attendance_method_index');
            $table->dropColumn(['check_in_method', 'check_out_method']);
        });
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['certificates_enabled', 'certificate_minimum_minutes']));
    }
};

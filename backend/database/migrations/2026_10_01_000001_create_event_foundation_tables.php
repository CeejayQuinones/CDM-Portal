<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('venue', 180);
            $table->string('venue_key', 180);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at'], 'events_status_schedule_index');
            $table->index(['venue_key', 'starts_at', 'ends_at'], 'events_venue_schedule_index');
        });

        Schema::create('event_audiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('audience_type', 24);
            $table->foreignId('course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->unsignedTinyInteger('year_level')->nullable();
            $table->foreignId('section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->timestamps();
            $table->index(['audience_type', 'course_id', 'year_level'], 'event_audiences_academic_index');
            $table->index(['audience_type', 'section_id'], 'event_audiences_section_index');
        });

        Schema::create('event_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role');
            $table->string('action', 40);
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->index(['event_id', 'created_at'], 'event_audit_event_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_audit_events');
        Schema::dropIfExists('event_audiences');
        Schema::dropIfExists('events');
    }
};

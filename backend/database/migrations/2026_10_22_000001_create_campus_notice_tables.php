<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->text('message');
            $table->string('category', 40);
            $table->string('priority', 20)->default('normal');
            $table->json('audiences');
            $table->string('status', 20)->default('draft');
            $table->timestamp('publication_start_at')->nullable();
            $table->timestamp('publication_end_at')->nullable();
            $table->timestamp('effective_start_at')->nullable();
            $table->timestamp('effective_end_at')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->json('recurrence_weekdays')->nullable();
            $table->boolean('requires_acknowledgement')->default(false);
            $table->string('official_source', 255)->nullable();
            $table->string('affected_scope', 255)->nullable();
            $table->string('target_route', 255)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'publication_start_at', 'publication_end_at'], 'campus_notices_publication_idx');
            $table->index(['category', 'priority']);
        });

        Schema::create('campus_notice_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_notice_id')->constrained('campus_notices')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->index(['campus_notice_id', 'created_at'], 'campus_notice_audit_notice_idx');
        });

        Schema::create('campus_notice_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_notice_id')->constrained('campus_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->unique(['campus_notice_id', 'user_id'], 'campus_notice_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_notice_acknowledgements');
        Schema::dropIfExists('campus_notice_audit_events');
        Schema::dropIfExists('campus_notices');
    }
};

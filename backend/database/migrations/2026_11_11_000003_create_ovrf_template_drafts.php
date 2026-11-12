<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovrf_template_drafts', function (Blueprint $table): void {
            $table->id(); // The immutable, monotonically increasing draft version.
            $table->string('original_filename');
            $table->string('private_path')->unique();
            $table->char('sha256', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('page_count');
            $table->string('status', 16)->default('draft');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        Schema::create('ovrf_template_draft_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ovrf_template_draft_id')->constrained('ovrf_template_drafts')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->timestamp('created_at');
            $table->index(['ovrf_template_draft_id', 'created_at'], 'ovrf_template_draft_events_history_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('ovrf_template_drafts')->exists()) {
            throw new RuntimeException('OVRF draft versions contain history; archive and approve a separate rollback plan.');
        }

        Schema::dropIfExists('ovrf_template_draft_events');
        Schema::dropIfExists('ovrf_template_drafts');
    }
};

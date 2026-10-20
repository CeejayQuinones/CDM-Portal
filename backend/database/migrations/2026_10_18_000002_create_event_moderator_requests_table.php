<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_moderator_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('active_request_key', 64)->nullable()->unique();
            $table->foreignId('assignment_id')->nullable()->constrained('event_role_assignments')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'status', 'requested_at'], 'event_moderator_request_status_index');
            $table->index(['requester_user_id', 'status', 'requested_at'], 'event_moderator_request_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_moderator_requests');
    }
};

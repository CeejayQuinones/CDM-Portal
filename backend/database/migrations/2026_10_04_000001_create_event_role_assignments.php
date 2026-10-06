<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsibility', 24);
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id'], 'event_role_assignment_event_user_unique');
            $table->index(['event_id', 'responsibility', 'revoked_at'], 'event_role_assignment_active_index');
            $table->index(['user_id', 'revoked_at', 'starts_at', 'ends_at'], 'event_role_assignment_user_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_role_assignments');
    }
};

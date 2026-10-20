<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->index(['subject_user_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_audit_events');
    }
};

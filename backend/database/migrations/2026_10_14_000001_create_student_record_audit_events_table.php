<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_record_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['student_id', 'created_at'], 'student_record_audit_student_created_index');
            $table->index(['actor_user_id', 'created_at'], 'student_record_audit_actor_created_index');
            $table->index(['action', 'created_at'], 'student_record_audit_action_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_record_audit_events');
    }
};

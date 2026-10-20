<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_sheet_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('professor_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['grade_sheet_id', 'student_id'], 'grade_conversation_context_unique');
            $table->index(['professor_id', 'updated_at']);
        });

        Schema::create('grade_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('unsent_at')->nullable();
            $table->timestamps();
            $table->index(['grade_conversation_id', 'read_at']);
            $table->index(['sender_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_messages');
        Schema::dropIfExists('grade_conversations');
    }
};

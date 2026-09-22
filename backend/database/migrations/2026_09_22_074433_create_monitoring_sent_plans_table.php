<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_sent_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('performance_record_id')
                ->nullable()
                ->constrained('monitoring_performance_records')
                ->nullOnDelete();
            $table->string('title', 180);
            $table->string('topic', 255)->nullable();
            $table->string('subject_code', 40)->nullable();
            $table->longText('plan_body');
            $table->string('source', 40)->default('gemini');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_sent_plans');
    }
};

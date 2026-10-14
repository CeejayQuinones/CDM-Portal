<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_record_recent_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->unique(['user_id', 'student_id']);
            $table->index(['user_id', 'viewed_at']);
        });

        Schema::create('student_record_saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('student_record_staff_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('visible_columns')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_record_staff_preferences');
        Schema::dropIfExists('student_record_saved_filters');
        Schema::dropIfExists('student_record_recent_views');
    }
};

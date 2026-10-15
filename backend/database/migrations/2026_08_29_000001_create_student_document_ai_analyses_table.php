<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_document_ai_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_document_id')
                ->unique()
                ->constrained('student_documents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('detected_document_type')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('extracted_data')->nullable();
            $table->json('checks')->nullable();
            $table->json('issues')->nullable();
            $table->string('recommendation')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_document_ai_analyses');
    }
};

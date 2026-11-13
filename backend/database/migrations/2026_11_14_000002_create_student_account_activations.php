<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_account_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique('saa_student_unique')->constrained('students')->cascadeOnDelete();
            $table->foreignId('prepared_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('record_reference', 120);
            $table->string('code_hash')->nullable();
            $table->string('token_hash')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_account_activations');
    }
};

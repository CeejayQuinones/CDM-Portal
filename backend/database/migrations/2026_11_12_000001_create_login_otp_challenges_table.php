<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client', 16);
            $table->string('code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('resend_available_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'client', 'consumed_at'], 'login_otp_user_client_active_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otp_challenges');
    }
};

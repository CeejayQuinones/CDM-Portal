<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_roster_import_previews', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->longText('rows');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['actor_user_id', 'expires_at'], 'sri_actor_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_roster_import_previews');
    }
};

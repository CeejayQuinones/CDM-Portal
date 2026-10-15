<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('preferred_display_name', 120)->nullable();
            $table->text('bio')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->json('academic_preferences')->nullable();
            $table->string('appearance', 12)->default('system');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_settings');
    }
};

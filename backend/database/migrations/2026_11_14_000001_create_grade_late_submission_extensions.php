<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_late_submission_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_sheet_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->dateTime('expires_at');
            $table->string('status', 16)->default('active');
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['grade_sheet_id', 'status', 'expires_at'], 'grade_late_ext_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_late_submission_extensions');
    }
};

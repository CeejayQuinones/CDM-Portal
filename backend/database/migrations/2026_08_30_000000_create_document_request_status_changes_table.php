<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_request_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registrar_staff_id')->constrained('registrar_staff')->restrictOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['document_request_id', 'created_at'], 'request_status_changes_request_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_status_changes');
    }
};

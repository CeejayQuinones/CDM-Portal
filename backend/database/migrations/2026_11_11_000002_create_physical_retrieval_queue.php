<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('requires_physical_retrieval')->default(false);
        });

        Schema::create('physical_retrieval_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->unsignedBigInteger('active_student_id')->nullable()->unique();
            $table->foreign('active_student_id')->references('id')->on('students')->restrictOnDelete();
            $table->string('status', 24)->default('awaiting');
            $table->string('cabinet_code_snapshot', 50)->nullable();
            $table->string('slot_code_snapshot', 100)->nullable();
            $table->foreignId('retrieved_by')->nullable()->constrained('registrar_staff')->restrictOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('registrar_staff')->restrictOnDelete();
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'physical_retrieval_tasks_status_date_index');
        });

        Schema::create('physical_retrieval_request', function (Blueprint $table): void {
            $table->foreignId('physical_retrieval_task_id')->constrained('physical_retrieval_tasks')->restrictOnDelete();
            $table->foreignId('document_request_id')->unique()->constrained('document_requests')->restrictOnDelete();
            $table->primary(['physical_retrieval_task_id', 'document_request_id'], 'physical_retrieval_request_primary');
        });

        Schema::create('physical_retrieval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('physical_retrieval_task_id')->constrained('physical_retrieval_tasks')->restrictOnDelete();
            $table->foreignId('registrar_staff_id')->nullable()->constrained('registrar_staff')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('cabinet_code_snapshot', 50)->nullable();
            $table->string('slot_code_snapshot', 100)->nullable();
            $table->timestamp('created_at');
            $table->index(['physical_retrieval_task_id', 'created_at'], 'physical_retrieval_events_task_date_index');
        });
    }

    public function down(): void
    {
        if (DB::table('physical_retrieval_tasks')->exists()
            || DB::table('document_types')->where('requires_physical_retrieval', true)->exists()) {
            throw new RuntimeException('Physical retrieval history or configuration exists; manual archival is required before rollback.');
        }

        Schema::dropIfExists('physical_retrieval_events');
        Schema::dropIfExists('physical_retrieval_request');
        Schema::dropIfExists('physical_retrieval_tasks');
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('requires_physical_retrieval'));
    }
};

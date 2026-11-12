<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_request_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['student_id', 'idempotency_key'], 'document_request_batches_student_key_unique');
            $table->index(['student_id', 'created_at'], 'document_request_batches_student_date_index');
        });

        Schema::table('document_requests', function (Blueprint $table): void {
            $table->foreignId('document_request_batch_id')->nullable()
                ->constrained('document_request_batches')->restrictOnDelete();
            $table->index(['document_request_batch_id', 'status'], 'document_requests_batch_status_index');
        });
    }

    public function down(): void
    {
        if (DB::table('document_request_batches')->exists()) {
            throw new RuntimeException('Document request groups contain history; manual archival is required before rollback.');
        }

        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropIndex('document_requests_batch_status_index');
            $table->dropConstrainedForeignId('document_request_batch_id');
        });
        Schema::dropIfExists('document_request_batches');
    }
};

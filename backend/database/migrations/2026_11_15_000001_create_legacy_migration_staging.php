<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_migration_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 20);
            $table->string('source_sha256', 64);
            $table->string('operator_reference', 100);
            $table->unsignedInteger('row_count')->default(0);
            $table->string('status', 20)->default('loading');
            $table->timestamps();
            $table->unique(['entity', 'source_sha256'], 'lmb_entity_source_unique');
        });

        Schema::create('legacy_migration_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_migration_batches')->restrictOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('source_key_sha256', 64)->nullable();
            $table->longText('payload'); // Encrypted by the model; no student details in indexed columns.
            $table->string('status', 20)->default('staged');
            $table->string('reason_code', 50)->nullable();
            $table->json('resolved_refs')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'source_row'], 'lmr_batch_row_unique');
            $table->index(['batch_id', 'status', 'id'], 'lmr_batch_status_idx');
            $table->index(['batch_id', 'source_key_sha256'], 'lmr_batch_key_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('legacy_migration_rows') && DB::table('legacy_migration_rows')->exists()) {
            throw new RuntimeException('Legacy staging contains records; approve a data-preserving cleanup before rollback.');
        }
        if (Schema::hasTable('legacy_migration_batches') && DB::table('legacy_migration_batches')->exists()) {
            throw new RuntimeException('Legacy staging contains batch history; approve a data-preserving cleanup before rollback.');
        }
        Schema::dropIfExists('legacy_migration_rows');
        Schema::dropIfExists('legacy_migration_batches');
    }
};

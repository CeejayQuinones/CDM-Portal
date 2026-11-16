<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_migration_batches', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 255)->nullable();
            $table->string('backup_reference', 255)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('legacy_migration_batches')->whereNotNull('approved_by')->exists()) {
            throw new RuntimeException('Migration approval history exists; do not drop its audit fields.');
        }
        Schema::table('legacy_migration_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'approval_reference', 'backup_reference']);
        });
    }
};

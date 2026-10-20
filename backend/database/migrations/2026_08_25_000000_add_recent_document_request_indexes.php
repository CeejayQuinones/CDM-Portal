<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DOCUMENT_REQUESTS_INDEX = 'doc_requests_updated_id_idx';

    private const APPOINTMENTS_INDEX = 'appointments_updated_id_idx';

    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->index(['updated_at', 'id'], self::DOCUMENT_REQUESTS_INDEX);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->index(['updated_at', 'id'], self::APPOINTMENTS_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(self::APPOINTMENTS_INDEX);
        });

        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropIndex(self::DOCUMENT_REQUESTS_INDEX);
        });
    }
};

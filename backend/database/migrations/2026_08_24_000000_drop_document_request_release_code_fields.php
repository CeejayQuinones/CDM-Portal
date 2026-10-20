<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('document_requests', 'verification_code')) {
            Schema::table('document_requests', function (Blueprint $table): void {
                $table->dropUnique('document_requests_verification_code_unique');
                $table->dropColumn('verification_code');
            });
        }

        if (Schema::hasColumn('document_requests', 'code_verified')) {
            Schema::table('document_requests', function (Blueprint $table): void {
                $table->dropColumn('code_verified');
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: the release-code flow and its data are obsolete.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table): void {
            $table->json('personal_details')->nullable()->after('classification');
        });

        Schema::table('enrollment_documents', function (Blueprint $table): void {
            $table->string('original_name')->nullable()->after('file_path');
            $table->string('mime_type', 100)->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_documents', function (Blueprint $table): void {
            $table->dropColumn(['original_name', 'mime_type']);
        });

        Schema::table('enrollment_applications', function (Blueprint $table): void {
            $table->dropColumn('personal_details');
        });
    }
};

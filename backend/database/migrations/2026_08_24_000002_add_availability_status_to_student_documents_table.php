<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_documents', function (Blueprint $table): void {
            $table->enum('availability_status', ['available', 'missing'])
                ->default('missing')
                ->after('verification_status');
            $table->index(['student_id', 'availability_status']);
        });

        DB::table('student_documents')
            ->where('verification_status', 'verified')
            ->update(['availability_status' => 'available']);
    }

    public function down(): void
    {
        Schema::table('student_documents', function (Blueprint $table): void {
            $table->dropIndex(['student_id', 'availability_status']);
            $table->dropColumn('availability_status');
        });
    }
};

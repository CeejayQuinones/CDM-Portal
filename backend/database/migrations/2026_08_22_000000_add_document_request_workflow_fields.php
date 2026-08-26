<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('release_date');
            $table->timestamp('processed_at')->nullable()->after('approved_at');
            $table->timestamp('ready_for_release_at')->nullable()->after('processed_at');
            $table->timestamp('released_at')->nullable()->after('ready_for_release_at');
            $table->timestamp('rejected_at')->nullable()->after('released_at');
            $table->index(['student_id', 'status']);
            $table->index(['registrar_staff_id', 'status']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('active_slot_key', 32)->nullable()->unique()->after('appointment_time');
            $table->index(['student_id', 'status']);
            $table->index(['appointment_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'status']);
            $table->dropIndex(['appointment_date', 'status']);
            $table->dropUnique(['active_slot_key']);
            $table->dropColumn('active_slot_key');
        });
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'status']);
            $table->dropIndex(['registrar_staff_id', 'status']);
            $table->dropColumn(['approved_at', 'processed_at', 'ready_for_release_at', 'released_at', 'rejected_at']);
        });
    }
};

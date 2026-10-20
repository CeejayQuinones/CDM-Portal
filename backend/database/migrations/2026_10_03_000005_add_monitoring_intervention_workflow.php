<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_notifications', function (Blueprint $table): void {
            $table->json('context_json')->nullable()->after('message');
            $table->index(['sender_user_id', 'student_id', 'risk_level', 'created_at'], 'risk_notice_duplicate_lookup_index');
        });

        Schema::create('monitoring_intervention_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('risk_notification_id')->nullable()->constrained('risk_notifications')->nullOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 50);
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('action', 80);
            $table->string('risk_level', 20)->nullable();
            $table->json('context_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_intervention_events');

        Schema::table('risk_notifications', function (Blueprint $table): void {
            $table->dropIndex('risk_notice_duplicate_lookup_index');
            $table->dropColumn('context_json');
        });
    }
};

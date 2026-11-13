<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_account_visit_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique('sav_request_reference_unique');
            $table->string('student_number', 20);
            $table->string('full_name', 200);
            $table->string('contact_email', 255);
            $table->date('preferred_date');
            $table->string('preferred_time', 20);
            $table->string('status', 20)->default('requested');
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('identity_checked_by_user_id')->nullable();
            $table->foreign('identity_checked_by_user_id', 'sav_identity_checker_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('identity_checked_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'preferred_date'], 'sav_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_account_visit_requests');
    }
};

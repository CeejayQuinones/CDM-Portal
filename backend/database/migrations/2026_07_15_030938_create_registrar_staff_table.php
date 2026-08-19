<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('registrar_staff', function (Blueprint $table) {

            $table->id();

            // Login Account
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Personal Profile
            $table->foreignId('user_profile_id')
                ->unique()
                ->constrained('user_profiles')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Employee Information
            $table->string('employee_number', 20)->unique();

            // Office Position
            $table->string('position', 100);

            // Employment Status
            $table->enum('employment_status', [
                'regular',
                'contractual',
                'part_time'
            ])->default('regular');

            // Account Status
            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registrar_staff');
    }
};

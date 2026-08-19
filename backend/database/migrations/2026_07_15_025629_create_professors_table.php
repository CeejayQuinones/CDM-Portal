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
        Schema::create('professors', function (Blueprint $table) {

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

            // Department
            $table->foreignId('department_id')
                ->constrained('departments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Employee Information
            $table->string('employee_number', 20)->unique();

            $table->string('position', 100)->nullable();

            $table->string('specialization')->nullable();

            $table->enum('employment_status', [
                'full_time',
                'part_time',
                'contractual'
            ])->default('full_time');

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
        Schema::dropIfExists('professors');
    }
};

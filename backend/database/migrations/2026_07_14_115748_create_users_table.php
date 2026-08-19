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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Login credentials
            $table->string('username', 50)->unique();
            $table->string('password');

            // Role (Admin, Student, Professor, Registrar)
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Account status
            $table->enum('status', [
                'pending',
                'active',
                'inactive',
                'rejected',
                'suspended'
            ])->default('pending');

            // Login history
            $table->timestamp('last_login')->nullable();
            $table->boolean('is_first_login')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

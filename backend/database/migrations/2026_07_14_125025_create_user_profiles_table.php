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
        Schema::create('user_profiles', function (Blueprint $table) {

            $table->id();

            // Link to login account
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Personal Information
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            // Basic Information
            $table->enum('gender', [
                'Male',
                'Female',
                'Prefer not to say'
            ]);

            $table->date('birth_date')->nullable();
            $table->string('civil_status', 30)->nullable();

            // Contact Information
            $table->string('email')->nullable()->unique();
            $table->string('contact_number', 20)->nullable();

            // Address
            $table->text('address')->nullable();

            // Profile Picture
            $table->string('profile_photo')->nullable();
            $table->string('nationality', 50)->default('Filipino');
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};

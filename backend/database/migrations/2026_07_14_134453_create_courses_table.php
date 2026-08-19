<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('courses', function (Blueprint $table) {

        $table->id();

        // Department Relationship
        $table->foreignId('department_id')
              ->constrained('departments')
              ->cascadeOnUpdate()
              ->restrictOnDelete();

        // Course Information
        $table->string('course_code', 20)->unique();
        $table->string('course_name', 150);

        // Duration
        $table->unsignedTinyInteger('years')->default(4);

        // Status
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
        Schema::dropIfExists('courses');
    }
};

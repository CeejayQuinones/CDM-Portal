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
        Schema::create('academic_years', function (Blueprint $table) {

            $table->id();

            // Academic Year
            $table->string('school_year', 20)->unique();

            // Example:
            // 2026-2027

            $table->date('start_date');
            $table->date('end_date');

            $table->enum('status', [
                'upcoming',
                'active',
                'completed'
            ])->default('upcoming');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};

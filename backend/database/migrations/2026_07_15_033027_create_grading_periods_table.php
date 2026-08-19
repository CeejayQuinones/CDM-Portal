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
        Schema::create('grading_periods', function (Blueprint $table) {

            $table->id();

            // Period Information
            $table->string('period_name', 30)->unique();

            // Display Order
            $table->unsignedTinyInteger('period_order');

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
        Schema::dropIfExists('grading_periods');
    }
};

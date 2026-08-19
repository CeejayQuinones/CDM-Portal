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
    Schema::create('document_types', function (Blueprint $table) {

        $table->id();

        // Document Information
        $table->string('document_name', 150)->unique();
        $table->text('description')->nullable();

        // Processing Information
        $table->decimal('processing_fee', 8, 2)->default(0);
        $table->unsignedTinyInteger('processing_days')->default(1);

        // Availability
        $table->boolean('requires_appointment')->default(false);

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
        Schema::dropIfExists('document_types');
    }
};

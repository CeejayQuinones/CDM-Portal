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
    Schema::create('document_storage_locations', function (Blueprint $table) {

        $table->id();

        // Storage Information
        $table->string('cabinet', 50);
        $table->string('drawer', 50)->nullable();
        $table->string('folder', 50)->nullable();
        $table->string('shelf', 50)->nullable();
        $table->string('remarks')->nullable();

        // Status
        $table->enum('status', [
            'available',
            'occupied',
            'inactive'
        ])->default('available');

        $table->timestamps();

        // Prevent duplicate storage locations
        $table->unique([
            'cabinet',
            'drawer',
            'folder',
            'shelf'
        ]);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_storage_locations');
    }
};

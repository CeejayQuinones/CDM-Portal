<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinet_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')
                ->constrained('cabinets')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('slot_code', 100);
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();

            $table->unique(['cabinet_id', 'slot_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_slots');
    }
};

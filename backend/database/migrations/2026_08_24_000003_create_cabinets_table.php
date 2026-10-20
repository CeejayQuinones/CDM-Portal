<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table): void {
            $table->id();
            $table->string('cabinet_code', 50)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('rows');
            $table->unsignedSmallInteger('columns');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinets');
    }
};

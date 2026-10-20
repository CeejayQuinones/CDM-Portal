<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_availability_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('block_saturday')->default(true);
            $table->boolean('block_sunday')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('appointment_availability_settings')->insert([
            'id' => 1,
            'block_saturday' => true,
            'block_sunday' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_availability_settings');
    }
};

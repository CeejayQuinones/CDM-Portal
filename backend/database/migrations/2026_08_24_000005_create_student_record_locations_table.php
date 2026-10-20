<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_record_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')
                ->unique()
                ->constrained('students')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('cabinet_slot_id')
                ->constrained('cabinet_slots')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_record_locations');
    }
};

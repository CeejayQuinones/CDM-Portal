<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->string('registrar_window', 20)->nullable();
        });

        Schema::create('appointment_window_defaults', function (Blueprint $table): void {
            $table->id();
            $table->string('service_window', 20)->unique();
            $table->unsignedSmallInteger('capacity');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_window_defaults');
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('registrar_window'));
    }
};

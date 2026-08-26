<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_data_records', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key', 80);
            $table->string('record_type', 40);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('record_created_at');
            $table->timestamps();

            $table->unique(['dataset_key', 'record_type', 'record_id'], 'generated_data_record_unique');
            $table->index(['dataset_key', 'record_type'], 'generated_data_record_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_data_records');
    }
};

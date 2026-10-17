<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_percentage', 5, 2);
            $table->decimal('max_percentage', 5, 2);
            $table->decimal('grade_point', 5, 2);
            $table->string('remark', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'min_percentage', 'max_percentage'], 'grading_scale_active_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_scales');
    }
};

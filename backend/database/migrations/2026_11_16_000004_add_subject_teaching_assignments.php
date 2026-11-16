<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_application_subjects', function (Blueprint $table): void {
            $table->foreignId('section_subject_id')->nullable()->after('subject_id')
                ->constrained('section_subjects')->restrictOnDelete();
        });

        Schema::table('enrollment_subjects', function (Blueprint $table): void {
            $table->foreignId('section_subject_id')->nullable()->after('subject_id')
                ->constrained('section_subjects')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_subjects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_subject_id');
        });

        Schema::table('enrollment_application_subjects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_subject_id');
        });
    }
};

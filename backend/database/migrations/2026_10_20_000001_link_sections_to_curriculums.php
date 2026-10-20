<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('curriculum_id')->nullable()->after('course_id')->constrained('curriculums')->restrictOnDelete();
            $table->index(['curriculum_id', 'year_level', 'semester_id'], 'sections_curriculum_level_term_index');
        });

        // Existing rows are linked only when their Course has one unambiguous active curriculum.
        DB::table('sections')->orderBy('id')->eachById(function ($section): void {
            $curricula = DB::table('curriculums')->where('course_id', $section->course_id)
                ->where('status', 'active')->limit(2)->pluck('id');
            if ($curricula->count() === 1) {
                DB::table('sections')->where('id', $section->id)->update(['curriculum_id' => $curricula->first()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex('sections_curriculum_level_term_index');
            $table->dropConstrainedForeignId('curriculum_id');
        });
    }
};

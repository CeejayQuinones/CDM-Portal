<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('admission_applicant_id')->nullable()->after('owner_user_id')->constrained('admission_applicants')->restrictOnDelete();
        });

        DB::table('enrollment_applications')->orderBy('id')->chunkById(500, function ($applications): void {
            foreach ($applications as $application) {
                $userId = DB::table('students')->where('id', $application->student_id)->value('user_id');
                if (! $userId) {
                    throw new RuntimeException("Enrollment application {$application->id} has no valid Student owner.");
                }
                DB::table('enrollment_applications')->where('id', $application->id)->update(['owner_user_id' => $userId]);
            }
        });

        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            $table->unsignedBigInteger('owner_user_id')->nullable(false)->change();
            $table->unique(['owner_user_id', 'academic_year_id', 'semester_id'], 'enrollment_application_owner_term_unique');
            $table->unique('admission_applicant_id', 'enrollment_application_admission_unique');
        });

        Schema::create('student_number_sequences', function (Blueprint $table) {
            $table->string('year_prefix', 2)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('enrollment_applications')->whereNull('student_id')->exists()) {
            throw new RuntimeException('Cannot restore mandatory student_id while first-time Enrollment applications exist.');
        }

        Schema::dropIfExists('student_number_sequences');
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropUnique('enrollment_application_owner_term_unique');
            $table->dropUnique('enrollment_application_admission_unique');
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('admission_applicant_id');
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};

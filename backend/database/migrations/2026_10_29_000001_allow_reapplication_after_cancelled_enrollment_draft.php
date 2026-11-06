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
            $table->unsignedBigInteger('blocking_owner_user_id')->nullable();
            $table->unsignedBigInteger('blocking_student_id')->nullable();
            $table->unsignedBigInteger('blocking_admission_applicant_id')->nullable();
        });

        DB::table('enrollment_applications')->orderBy('id')->chunkById(500, function ($applications): void {
            foreach ($applications as $application) {
                // Only an unsubmitted, unreviewed, unfinalized cancelled draft releases its keys.
                $released = $application->status === 'cancelled'
                    && $application->submitted_at === null
                    && $application->reviewed_at === null
                    && $application->reviewed_by === null
                    && $application->finalized_at === null
                    && $application->enrollment_id === null
                    && DB::table('enrollment_workflow_events')
                        ->where('subject_type', 'application')->where('subject_id', $application->id)
                        ->where('action', 'enrollment.cancelled')->where('actor_user_id', $application->owner_user_id)
                        ->whereIn('actor_role', ['Student', 'Guest'])->exists();
                if ($released) {
                    continue;
                }
                DB::table('enrollment_applications')->where('id', $application->id)->update([
                    'blocking_owner_user_id' => $application->owner_user_id,
                    'blocking_student_id' => $application->student_id,
                    'blocking_admission_applicant_id' => $application->admission_applicant_id,
                ]);
            }
        });

        // Multiple NULL values are allowed in unique indexes on both SQLite and MariaDB.
        // Build the replacement constraints before removing the existing protections.
        Schema::table('enrollment_applications', function (Blueprint $table) {
            // Keep indexes on the original FK columns when their old unique indexes go away.
            $table->index('owner_user_id', 'enrollment_application_owner_lookup_idx');
            $table->index('student_id', 'enrollment_application_student_lookup_idx');
            $table->index('admission_applicant_id', 'enrollment_application_admission_lookup_idx');
            $table->unique(['blocking_owner_user_id', 'academic_year_id', 'semester_id'], 'enrollment_application_blocking_owner_term_unique');
            $table->unique(['blocking_student_id', 'academic_year_id', 'semester_id'], 'enrollment_application_blocking_student_term_unique');
            $table->unique('blocking_admission_applicant_id', 'enrollment_application_blocking_admission_unique');
        });
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropUnique('enrollment_application_owner_term_unique');
            $table->dropUnique('enrollment_application_student_term_unique');
            $table->dropUnique('enrollment_application_admission_unique');
        });
    }

    public function down(): void
    {
        // Restoring the old indexes would fail if anyone has reapplied. Refuse rollback
        // rather than deleting or rewriting either the cancelled or replacement record.
        foreach ([
            ['owner_user_id', 'academic_year_id', 'semester_id'],
            ['student_id', 'academic_year_id', 'semester_id'],
            ['admission_applicant_id'],
        ] as $columns) {
            $query = DB::table('enrollment_applications')->select($columns)->selectRaw('COUNT(*) as duplicates')
                ->groupBy(...$columns)->havingRaw('COUNT(*) > 1');
            if (count($columns) === 1) {
                $query->whereNotNull($columns[0]);
            } elseif ($columns[0] === 'student_id') {
                $query->whereNotNull('student_id');
            }
            if ($query->exists()) {
                throw new RuntimeException('Cannot restore Enrollment uniqueness while historical and replacement applications share an identity. Preserve both records and use a forward repair.');
            }
        }

        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->unique(['owner_user_id', 'academic_year_id', 'semester_id'], 'enrollment_application_owner_term_unique');
            $table->unique(['student_id', 'academic_year_id', 'semester_id'], 'enrollment_application_student_term_unique');
            $table->unique('admission_applicant_id', 'enrollment_application_admission_unique');
        });
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropUnique('enrollment_application_blocking_owner_term_unique');
            $table->dropUnique('enrollment_application_blocking_student_term_unique');
            $table->dropUnique('enrollment_application_blocking_admission_unique');
            $table->dropIndex('enrollment_application_owner_lookup_idx');
            $table->dropIndex('enrollment_application_student_lookup_idx');
            $table->dropIndex('enrollment_application_admission_lookup_idx');
            $table->dropColumn(['blocking_owner_user_id', 'blocking_student_id', 'blocking_admission_applicant_id']);
        });
    }
};

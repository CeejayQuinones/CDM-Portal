<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cabinets')) {
            DB::table('cabinets')
                ->where('description', 'Starter cabinet for physical student records.')
                ->update(['description' => 'Registrar physical records cabinet.']);
        }

        if (Schema::hasTable('student_record_locations')) {
            DB::table('student_record_locations')
                ->where('remarks', 'Starter physical record location for manual testing.')
                ->update(['remarks' => 'Assigned to registrar records storage.']);
        }

        if (Schema::hasTable('student_documents')) {
            DB::table('student_documents')
                ->where('remarks', 'Starter record: document is on file.')
                ->update(['remarks' => 'Verified student record.']);
            DB::table('student_documents')
                ->where('remarks', 'Starter record: document is not yet on file.')
                ->update(['remarks' => 'Awaiting supporting document.']);
        }
    }

    public function down(): void
    {
        // Normalized display text should not be changed back to staging terminology.
    }
};

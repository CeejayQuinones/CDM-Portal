<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')
            ->whereIn('document_name', ['Birth Certificate', 'Form 137', 'Form 138'])
            ->update(['student_requestable' => false]);
    }

    public function down(): void
    {
        DB::table('document_types')
            ->whereIn('document_name', ['Birth Certificate', 'Form 137', 'Form 138'])
            ->update(['student_requestable' => true]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->string('workflow_category', 32)->default('official_issuance')->after('description');
            $table->boolean('student_requestable')->default(true)->after('requires_appointment');
            $table->boolean('student_uploadable')->default(false)->after('student_requestable');
            $table->index(['status', 'student_requestable'], 'document_types_requestable_index');
            $table->index(['status', 'student_uploadable'], 'document_types_uploadable_index');
        });

        DB::table('document_types')
            ->whereIn('document_name', ['Birth Certificate', 'Form 137', 'Form 138', 'Good Moral Certificate'])
            ->update(['workflow_category' => 'supporting_record', 'student_uploadable' => true]);
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropIndex('document_types_requestable_index');
            $table->dropIndex('document_types_uploadable_index');
            $table->dropColumn(['workflow_category', 'student_requestable', 'student_uploadable']);
        });
    }
};

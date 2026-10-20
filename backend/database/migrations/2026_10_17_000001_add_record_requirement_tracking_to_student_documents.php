<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_documents', function (Blueprint $table): void {
            $table->unsignedInteger('verification_version')
                ->default(1)
                ->after('verification_status');

            $table->timestamp('verified_at')
                ->nullable()
                ->after('submitted_date');

            $table->foreignId('verified_by_user_id')
                ->nullable()
                ->after('verified_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('physical_copy_status', [
                'not_required',
                'required',
                'submitted',
            ])
                ->default('not_required')
                ->after('verified_by_user_id');

            $table->timestamp('physical_copy_required_at')
                ->nullable()
                ->after('physical_copy_status');

            $table->timestamp('physical_copy_received_at')
                ->nullable()
                ->after('physical_copy_required_at');

            $table->foreignId('physical_copy_required_by_user_id')
                ->nullable()
                ->after('physical_copy_received_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('physical_copy_received_by_user_id')
                ->nullable()
                ->after('physical_copy_required_by_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('physical_copy_instruction')
                ->nullable()
                ->after('physical_copy_received_by_user_id');

            $table->index(
                ['physical_copy_status', 'student_id'],
                'student_documents_physical_attention_index'
            );

            $table->index(
                ['verification_status', 'availability_status'],
                'student_documents_digital_attention_index'
            );
        });

        Schema::create('student_document_submissions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('student_document_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('version');

            $table->string('file_path');

            $table->foreignId('submitted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('submitted_at');

            $table->enum('verification_status', [
                'pending',
                'verified',
                'rejected',
            ])->default('pending');

            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->text('review_remark')->nullable();

            $table->timestamps();

            $table->unique(
                ['student_document_id', 'version'],
                'sds_document_version_unique'
            );

            $table->index(
                ['verification_status', 'submitted_at'],
                'sds_verify_submitted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_document_submissions');

        Schema::table('student_documents', function (Blueprint $table): void {
            $table->dropIndex('student_documents_physical_attention_index');
            $table->dropIndex('student_documents_digital_attention_index');

            $table->dropConstrainedForeignId('physical_copy_received_by_user_id');
            $table->dropConstrainedForeignId('physical_copy_required_by_user_id');
            $table->dropConstrainedForeignId('verified_by_user_id');

            $table->dropColumn([
                'verification_version',
                'verified_at',
                'physical_copy_status',
                'physical_copy_required_at',
                'physical_copy_received_at',
                'physical_copy_instruction',
            ]);
        });
    }
};

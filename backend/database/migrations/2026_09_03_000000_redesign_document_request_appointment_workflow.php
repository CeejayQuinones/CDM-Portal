<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE document_requests MODIFY status ENUM('pending','approved','rejected','completed','cancelled','processing','ready_for_release','released') NOT NULL DEFAULT 'pending'");
        }
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('approved_at');
            $table->string('verification_code_lookup', 64)->nullable()->unique()->after('rejected_at');
            $table->string('verification_code_hash')->nullable()->after('verification_code_lookup');
            $table->timestamp('code_verified_at')->nullable()->after('verification_code_hash');
        });

        Schema::create('appointment_date_capacities', function (Blueprint $table): void {
            $table->id();
            $table->date('appointment_date')->unique();
            $table->unsignedSmallInteger('capacity')->default(5);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('document_request_status_changes', function (Blueprint $table): void {
            $table->foreignId('registrar_staff_id')->nullable()->change();
            $table->string('actor_type', 20)->default('registrar')->after('registrar_staff_id');
        });

        DB::table('document_requests')->where('status', 'released')->update([
            'status' => 'completed',
            'completed_at' => DB::raw('COALESCE(released_at, updated_at)'),
        ]);

        $legacyIds = DB::table('document_requests')->whereIn('status', ['processing', 'ready_for_release'])->pluck('id');
        foreach ($legacyIds->chunk(500) as $ids) {
            DB::table('document_requests')->whereIn('id', $ids)->update(['status' => 'pending']);
            $now = now();
            DB::table('document_request_status_changes')->insert(collect($ids)->map(fn ($id) => [
                'document_request_id' => $id,
                'registrar_staff_id' => null,
                'actor_type' => 'system',
                'from_status' => 'legacy_active',
                'to_status' => 'pending',
                'action' => 'workflow_migrated',
                'reason' => 'Legacy active request returned to pending for Registrar review; no verification event was inferred.',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }


        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE document_requests MODIFY status ENUM('pending','approved','rejected','completed','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_date_capacities');
        Schema::table('document_request_status_changes', fn (Blueprint $table) => $table->dropColumn('actor_type'));
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropUnique(['verification_code_lookup']);
            $table->dropColumn(['completed_at', 'verification_code_lookup', 'verification_code_hash', 'code_verified_at']);
        });
    }
};

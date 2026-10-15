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

        $this->migrateReleasedRequests();
        $this->migrateLegacyActiveRequests('processing');
        $this->migrateLegacyActiveRequests('ready_for_release');

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

    private function migrateReleasedRequests(): void
    {
        DB::table('document_requests')
            ->select('id')
            ->where('status', 'released')
            ->orderBy('id')
            ->chunkById(500, function ($requests): void {
                DB::transaction(function () use ($requests): void {
                    $ids = DB::table('document_requests')
                        ->whereIn('id', $requests->pluck('id'))
                        ->where('status', 'released')
                        ->lockForUpdate()
                        ->pluck('id');

                    if ($ids->isEmpty()) {
                        return;
                    }

                    DB::table('document_requests')
                        ->whereIn('id', $ids)
                        ->where('status', 'released')
                        ->update([
                            'status' => 'completed',
                            'completed_at' => DB::raw('COALESCE(released_at, updated_at)'),
                        ]);

                    $now = now();
                    DB::table('document_request_status_changes')->insert($ids->map(fn ($id) => [
                        'document_request_id' => $id,
                        'registrar_staff_id' => null,
                        'actor_type' => 'system',
                        'from_status' => 'released',
                        'to_status' => 'completed',
                        'action' => 'workflow_migrated',
                        'reason' => 'Legacy released status renamed to completed by the workflow migration; this does not represent a new physical document-release event.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });
            });
    }

    private function migrateLegacyActiveRequests(string $fromStatus): void
    {
        DB::table('document_requests')
            ->select('id')
            ->where('status', $fromStatus)
            ->orderBy('id')
            ->chunkById(500, function ($requests) use ($fromStatus): void {
                DB::transaction(function () use ($fromStatus, $requests): void {
                    $ids = DB::table('document_requests')
                        ->whereIn('id', $requests->pluck('id'))
                        ->where('status', $fromStatus)
                        ->lockForUpdate()
                        ->pluck('id');

                    if ($ids->isEmpty()) {
                        return;
                    }

                    // There is no appointment status-audit table. This changes only the
                    // active state and deliberately preserves dates, timestamps, and history.
                    DB::table('appointments')
                        ->whereIn('document_request_id', $ids)
                        ->where('status', 'confirmed')
                        ->update(['status' => 'pending']);

                    DB::table('document_requests')
                        ->whereIn('id', $ids)
                        ->where('status', $fromStatus)
                        ->update(['status' => 'pending']);

                    $now = now();
                    DB::table('document_request_status_changes')->insert($ids->map(fn ($id) => [
                        'document_request_id' => $id,
                        'registrar_staff_id' => null,
                        'actor_type' => 'system',
                        'from_status' => $fromStatus,
                        'to_status' => 'pending',
                        'action' => 'workflow_migrated',
                        'reason' => 'Legacy active request returned to pending for Registrar review; no verification event was inferred.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });
            });
    }
};

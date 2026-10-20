<?php

namespace App\Console\Commands;

use Database\Seeders\LargeDatasetSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyLargeDatasetCleanup extends Command
{
    protected $signature = 'portal:legacy-large-dataset-cleanup
        {--dry-run : Analyze and report without changing data}
        {--execute : Delete only strictly confirmed legacy fixture rows}';

    protected $description = 'Safely identify and remove the pre-ledger LargeDatasetSeeder fixture';

    private const PURPOSES = [
        'Scholarship requirement', 'Employment requirement', 'Transfer requirement',
        'Personal records', 'Board examination requirement', 'Government requirement', 'School requirement',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Legacy large-dataset cleanup is disabled in production.');

            return self::FAILURE;
        }
        if ((bool) $this->option('dry-run') === (bool) $this->option('execute')) {
            $this->error('Choose exactly one mode: --dry-run or --execute.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('generated_data_records')) {
            $this->info('No generated-data ledger exists; nothing can be proven or deleted.');

            return self::SUCCESS;
        }

        $plan = $this->buildPlan();
        $this->report($plan, (bool) $this->option('dry-run'));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $deleted = DB::transaction(fn (): array => $this->executePlan($plan));
        $this->info('Rows deleted: '.json_encode($deleted, JSON_THROW_ON_ERROR));
        $this->info('Cleanup completed. Run --dry-run again to verify that no removable legacy rows remain.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildPlan(): array
    {
        $ledger = DB::table('generated_data_records')
            ->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])
            ->where('record_type', LargeDatasetSeeder::GENERATED_USER_RECORD_TYPE)
            ->get(['record_id', 'record_created_at']);
        $stamps = $ledger->keyBy(fn ($row) => (int) $row->record_id);
        $confirmedUsers = DB::table('users')->whereIn('id', $stamps->keys())
            ->get(['id', 'created_at', 'updated_at'])->filter(fn ($row) => (string) $row->created_at === (string) $stamps[(int) $row->id]->record_created_at);
        $users = $confirmedUsers->pluck('id')->map(fn ($id) => (int) $id)->values();
        $profiles = $this->sameRunIdentity('user_profiles', $users);
        $students = $this->sameRunIdentity('students', $users);
        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->values();

        $documents = DB::table('student_documents')->whereIn('student_id', $studentIds)
            ->whereNull('file_path')->whereNull('document_storage_location_id')
            ->whereIn('remarks', ['Verified student record.', 'Awaiting supporting document.'])
            ->whereIn('availability_status', ['available', 'missing'])->pluck('id');
        $requests = DB::table('document_requests')->whereIn('student_id', $studentIds)
            ->whereIn('purpose', self::PURPOSES)->whereBetween('quantity', [1, 3])
            ->whereIn('status', ['pending', 'approved', 'completed', 'rejected', 'cancelled'])->pluck('id');
        $appointments = DB::table('appointments')->whereIn('student_id', $studentIds)
            ->whereIn('document_request_id', $requests)->where('purpose', 'Document request collection.')
            ->where('appointment_time', '00:00:00')->whereIn('status', ['pending', 'confirmed', 'completed', 'cancelled'])->pluck('id');
        $statusChanges = DB::table('document_request_status_changes as changes')
            ->join('document_requests as requests', 'requests.id', '=', 'changes.document_request_id')
            ->whereIn('requests.student_id', $studentIds)
            ->whereIn('requests.purpose', self::PURPOSES)->whereBetween('requests.quantity', [1, 3])
            ->whereIn('requests.status', ['pending', 'approved', 'completed', 'rejected', 'cancelled'])
            ->whereColumn('changes.created_at', '<=', 'requests.updated_at')->pluck('changes.id');
        $locations = DB::table('student_record_locations')->whereIn('student_id', $studentIds)
            ->whereIn('remarks', ['Filed with complete student records.', 'Assigned to registrar records storage.'])
            ->pluck('id');

        $protected = [];
        $addReasons = function (Collection $ids, string $reason) use (&$protected): void {
            foreach ($ids->unique() as $id) {
                $protected[(int) $id][] = $reason;
            }
        };
        $addReasons($students->filter(fn ($student) => (string) $student->updated_at !== (string) $student->created_at)->pluck('id'),
            'Student identity was edited after generation');
        $studentByUser = $students->pluck('id', 'user_id');
        $mapUsers = fn (Collection $ids): Collection => $ids->map(fn ($id) => $studentByUser[(int) $id] ?? null)->filter()->values();
        $addReasons($mapUsers(DB::table('admission_applicants')->whereIn('user_id', $users)->pluck('user_id')), 'Admission history exists');
        $addReasons(DB::table('admission_applicants')->whereIn('converted_student_id', $studentIds)->pluck('converted_student_id'), 'Admission history exists');
        $addReasons(DB::table('enrollment_applications')->whereIn('student_id', $studentIds)->pluck('student_id'), 'Enrollment application exists');
        $addReasons(DB::table('enrollments')->whereIn('student_id', $studentIds)->pluck('student_id'), 'final academic Enrollment exists');
        $addReasons(DB::table('student_settings')->whereIn('student_id', $studentIds)->pluck('student_id'), 'Student settings exist');
        $addReasons(DB::table('risk_notifications')->whereIn('student_id', $studentIds)->pluck('student_id'), 'Student risk activity exists');
        $addReasons($mapUsers(DB::table('notifications')->where('notifiable_type', 'App\\Models\\User')
            ->whereIn('notifiable_id', $users)->pluck('notifiable_id')), 'User notifications exist');
        $addReasons($mapUsers(DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', $users)->pluck('tokenable_id')), 'Active login tokens exist');
        foreach (['student_documents' => $documents, 'document_requests' => $requests,
            'appointments' => $appointments, 'student_record_locations' => $locations] as $table => $ids) {
            $addReasons(DB::table($table)->whereIn('student_id', $studentIds)->whereNotIn('id', $ids)->pluck('student_id'),
                "non-fixture {$table} activity exists");
        }
        $lateChanges = DB::table('document_request_status_changes as changes')
            ->join('document_requests as requests', 'requests.id', '=', 'changes.document_request_id')
            ->whereIn('requests.student_id', $studentIds)
            ->whereIn('requests.purpose', self::PURPOSES)->whereBetween('requests.quantity', [1, 3])
            ->whereIn('requests.status', ['pending', 'approved', 'completed', 'rejected', 'cancelled'])
            ->whereColumn('changes.created_at', '>', 'requests.updated_at')->pluck('requests.student_id');
        $addReasons($lateChanges, 'later document-request workflow activity exists');
        $documentCounts = DB::table('student_documents')->whereIn('student_id', $studentIds)
            ->whereIn('id', $documents)->selectRaw('student_id, COUNT(*) total')->groupBy('student_id')->pluck('total', 'student_id');
        $requestCounts = DB::table('document_requests')->whereIn('student_id', $studentIds)
            ->whereIn('id', $requests)->selectRaw('student_id, COUNT(*) total')->groupBy('student_id')->pluck('total', 'student_id');
        $locationCounts = DB::table('student_record_locations')->whereIn('student_id', $studentIds)
            ->whereIn('id', $locations)->selectRaw('student_id, COUNT(*) total')->groupBy('student_id')->pluck('total', 'student_id');
        $invalidStructure = $studentIds->filter(function (int $id) use ($documentCounts, $requestCounts, $locationCounts): bool {
            $counts = [(int) ($documentCounts[$id] ?? 0), (int) ($requestCounts[$id] ?? 0), (int) ($locationCounts[$id] ?? 0)];

            return $counts !== [3, 5, 1] && $counts !== [0, 0, 0];
        });
        $addReasons($invalidStructure, 'legacy child-row structure is incomplete or ambiguous');
        $protected = array_map(fn (array $reasons): array => array_values(array_unique($reasons)), $protected);

        // Fixture-only records may be removed from a protected academic identity,
        // but only when its complete original 3/5/1 child structure is intact.
        $fixtureSafeStudents = $studentIds->diff($invalidStructure)->filter(function (int $id) use ($protected): bool {
            return collect($protected[$id] ?? [])->doesntContain(fn (string $reason): bool => str_starts_with($reason, 'non-fixture')
                || str_starts_with($reason, 'later '));
        })->values();
        $documents = DB::table('student_documents')->whereIn('student_id', $fixtureSafeStudents)->whereIn('id', $documents)->pluck('id');
        $requests = DB::table('document_requests')->whereIn('student_id', $fixtureSafeStudents)->whereIn('id', $requests)->pluck('id');
        $appointments = DB::table('appointments')->whereIn('student_id', $fixtureSafeStudents)->whereIn('id', $appointments)->pluck('id');
        $statusChanges = DB::table('document_request_status_changes as changes')
            ->join('document_requests as requests', 'requests.id', '=', 'changes.document_request_id')
            ->whereIn('requests.student_id', $fixtureSafeStudents)
            ->whereColumn('changes.created_at', '<=', 'requests.updated_at')->pluck('changes.id');
        $locations = DB::table('student_record_locations')->whereIn('student_id', $fixtureSafeStudents)->whereIn('id', $locations)->pluck('id');

        $cabinetLedger = DB::table('generated_data_records')
            ->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])
            ->where('record_type', LargeDatasetSeeder::GENERATED_CABINET_RECORD_TYPE)->get(['record_id', 'record_created_at'])
            ->keyBy(fn ($row) => (int) $row->record_id);
        $cabinets = DB::table('cabinets')->whereIn('id', $cabinetLedger->keys())->get(['id', 'created_at'])
            ->filter(fn ($row) => (string) $row->created_at === (string) $cabinetLedger[(int) $row->id]->record_created_at)
            ->pluck('id');
        $cabinetSlots = DB::table('cabinet_slots')->whereIn('cabinet_id', $cabinets)->pluck('id');

        $deletableStudents = $studentIds->reject(fn (int $id) => isset($protected[$id]))->values();

        return compact('users', 'profiles', 'students', 'studentIds', 'documents', 'requests', 'appointments',
            'statusChanges', 'locations', 'cabinets', 'cabinetSlots', 'protected', 'deletableStudents');
    }

    private function sameRunIdentity(string $table, Collection $users): Collection
    {
        return DB::table($table)->join('users', 'users.id', '=', $table.'.user_id')
            ->whereIn('users.id', $users)->whereColumn($table.'.created_at', 'users.created_at')
            ->select($table.'.*')->get();
    }

    /** @param array<string, mixed> $plan */
    private function report(array $plan, bool $dryRun): void
    {
        $this->info(($dryRun ? 'DRY RUN' : 'EXECUTE').' — strict legacy LargeDataset ownership analysis');
        $this->table(['Classification', 'Count'], [
            ['confirmed generated Users', $plan['users']->count()],
            ['confirmed generated Students', $plan['studentIds']->count()],
            ['confirmed Student documents', $plan['documents']->count()],
            ['confirmed document requests', $plan['requests']->count()],
            ['confirmed request status changes', $plan['statusChanges']->count()],
            ['confirmed appointments', $plan['appointments']->count()],
            ['confirmed record locations', $plan['locations']->count()],
            ['confirmed generated cabinets', $plan['cabinets']->count()],
            ['confirmed generated cabinet slots', $plan['cabinetSlots']->count()],
            ['identities removable', $plan['deletableStudents']->count()],
            ['protected Students retained', count($plan['protected'])],
        ]);
        foreach (collect($plan['protected'])->flatMap(fn ($reasons) => $reasons)->countBy() as $reason => $count) {
            $this->warn("Protected {$count}: {$reason}");
        }
        if ($dryRun) {
            $this->comment('No rows changed. Use --execute only after reviewing these counts.');
        }
    }

    /** @param array<string, mixed> $plan @return array<string, int> */
    private function executePlan(array $plan): array
    {
        $deleted = [];
        foreach (['document_request_status_changes' => 'statusChanges', 'appointments' => 'appointments',
            'student_documents' => 'documents', 'student_record_locations' => 'locations',
            'document_requests' => 'requests'] as $table => $key) {
            $deleted[$table] = $this->deleteIds($table, $plan[$key]);
        }

        $deletable = $plan['deletableStudents'];
        $deletableUsers = $plan['students']->whereIn('id', $deletable)->pluck('user_id');
        $deletableProfiles = $plan['profiles']->whereIn('user_id', $deletableUsers)->pluck('id');
        $deleted['students'] = $this->deleteIds('students', $deletable);
        $deleted['user_profiles'] = $this->deleteIds('user_profiles', $deletableProfiles);
        $deleted['users'] = $this->deleteIds('users', $deletableUsers);
        $emptySlots = DB::table('cabinet_slots')->whereIn('id', $plan['cabinetSlots'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('student_record_locations')
                ->whereColumn('student_record_locations.cabinet_slot_id', 'cabinet_slots.id'))->pluck('id');
        $deleted['cabinet_slots'] = $this->deleteIds('cabinet_slots', $emptySlots);
        $emptyCabinets = DB::table('cabinets')->whereIn('id', $plan['cabinets'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cabinet_slots')
                ->whereColumn('cabinet_slots.cabinet_id', 'cabinets.id'))->pluck('id');
        $deleted['cabinets'] = $this->deleteIds('cabinets', $emptyCabinets);
        DB::table('generated_data_records')
            ->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])
            ->where('record_type', LargeDatasetSeeder::GENERATED_CABINET_RECORD_TYPE)
            ->whereIn('record_id', $emptyCabinets)->delete();
        DB::table('generated_data_records')
            ->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])
            ->where('record_type', LargeDatasetSeeder::GENERATED_CABINET_RECORD_TYPE)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cabinets')
                ->whereColumn('cabinets.id', 'generated_data_records.record_id'))->delete();
        DB::table('generated_data_records')
            ->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])
            ->where('record_type', LargeDatasetSeeder::GENERATED_USER_RECORD_TYPE)
            ->whereIn('record_id', $deletableUsers)->delete();

        return $deleted;
    }

    private function deleteIds(string $table, Collection $ids): int
    {
        return $ids->chunk(500)->sum(fn (Collection $chunk): int => DB::table($table)->whereIn('id', $chunk)->delete());
    }
}

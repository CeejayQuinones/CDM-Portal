<?php

// Opt-in only. Uses synthetic fixtures tracked by exact IDs; never migrates or drops tables.
// Run: php scripts/verify-admission-concurrency.php --run-with-fixtures
use App\Models\AcademicYear;
use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionAuditEvent;
use App\Models\Admission\AdmissionCycle;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionAuditWriter;
use App\Services\Admission\AdmissionIdentityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function waitFor(callable $condition, string $description): void
{
    $deadline = microtime(true) + 30;
    do {
        if ($condition()) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: '.$description);
}

function readJson(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function writeJson(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}

function databaseFingerprint(): array
{
    $snapshot = [];
    foreach (DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $table) {
        $quoted = '`'.str_replace('`', '``', $table->name).'`';
        $ddl = array_values((array) DB::selectOne('SHOW CREATE TABLE '.$quoted))[1];
        $hashes = [];
        foreach (DB::cursor('SELECT * FROM '.$quoted) as $row) {
            $hashes[] = hash('sha256', serialize((array) $row));
        }
        sort($hashes, SORT_STRING);
        $snapshot[$table->name] = [
            'schema' => preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl),
            'rows' => count($hashes),
            'hash' => hash('sha256', implode('', $hashes)),
        ];
    }

    return $snapshot;
}

$originalName = config('database.default');
$original = DB::connection($originalName)->getConfig();
verify(($original['driver'] ?? null) === 'mysql', 'This verifier requires the real mysql driver');
verify(! $app->environment('production'), 'Never run synthetic fixtures in the production environment');

if (($argv[1] ?? '') === '--worker') {
    $stateFile = $argv[2] ?? '';
    $jobId = $argv[3] ?? '';
    $state = readJson($stateFile);
    verify(isset($state['jobs'][$jobId]), 'Unknown worker job');
    verify(DB::selectOne('SELECT DATABASE() AS name')->name === $state['database'], 'Worker database mismatch');
    verify((bool) preg_match('/^[a-f0-9]{8}$/D', $state['nonce']), 'Invalid fixture nonce');
    $job = $state['jobs'][$jobId];
    $user = User::query()->findOrFail($job['user_id']);
    $cycle = AdmissionCycle::query()->findOrFail($job['cycle_id']);
    verify(str_starts_with($user->username, 'av-'.$state['nonce'].'-'), 'Worker must use its own synthetic user');
    verify(str_starts_with($cycle->code, 'AV'.$state['nonce'].'-'), 'Worker must use its own synthetic cycle');
    $attempts = 0;
    Str::createUuidsUsing(function () use (&$attempts, $job) {
        $attempts++;

        return isset($job['uuid']) && ($attempts === 1 || ($job['exhaust'] ?? false))
            ? Uuid::fromString($job['uuid']) : Uuid::uuid4();
    });
    if ($job['rollback'] ?? false) {
        $app->bind(AdmissionAuditWriter::class, fn () => new class extends AdmissionAuditWriter
        {
            public function identityCreated(User $actor, AdmissionApplicant $applicant): AdmissionAuditEvent
            {
                parent::identityCreated($actor, $applicant);
                throw new RuntimeException('Injected failure after audit insertion');
            }
        });
    }
    $prefix = dirname($stateFile).'/'.$jobId;
    writeJson($prefix.'.ready', ['connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    waitFor(fn () => file_exists($stateFile.'.go'), 'parent start gate');
    try {
        if ($job['endpoint'] ?? false) {
            // Real HTTP kernel; only authentication identity is supplied by the test.
            config(['cache.default' => 'array']);
            Sanctum::actingAs($user);
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $request = Request::create('/api/admission/applications', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
            $response = $kernel->handle($request);
            $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $result = ['status' => $response->getStatusCode(), 'code' => $body['code'] ?? null,
                'number' => $body['data']['application']['applicant_number'] ?? null];
            $kernel->terminate($request, $response);
        } else {
            $applicant = app(AdmissionIdentityService::class)->create($user, $cycle);
            $result = ['status' => 'created', 'id' => $applicant->id, 'number' => $applicant->applicant_number];
        }
    } catch (ValidationException $exception) {
        $result = ['status' => 'validation', 'errors' => $exception->errors()];
    } catch (UniqueConstraintViolationException) {
        $result = ['status' => 'unique_constraint'];
    } catch (Throwable $exception) {
        $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
    }
    writeJson($prefix.'.result', [...$result, 'number_attempts' => $attempts]);
    exit(0);
}

verify(($argv[1] ?? '') === '--run-with-fixtures', 'Opt in with --run-with-fixtures; no database changes have been made');
verify(Schema::hasTable('admission_audit_events'), 'Deploy the reviewed audit migration separately before verification');
$server = DB::connection($originalName);
$database = DB::selectOne('SELECT DATABASE() AS name')->name;
$nonce = bin2hex(random_bytes(4));
$directory = sys_get_temp_dir().'/admission_verify_'.$nonce;
verify(mkdir($directory, 0700), 'Cannot create private verification directory');
$processes = [];
$userIds = [];
$cycleIds = [];
$yearId = null;
$ledger = function () use (&$userIds, &$cycleIds, &$yearId, $directory, $database, $nonce): void {
    writeJson($directory.'/fixtures.json', ['database' => $database, 'nonce' => $nonce, 'users' => $userIds, 'cycles' => $cycleIds, 'year' => $yearId]);
};
$before = databaseFingerprint();
$report = ['database' => $database, 'fixture_nonce' => $nonce, 'server_version' => $server->selectOne('SELECT VERSION() AS version')->version];
$run = function (array $jobs, ?int $lockedUser = null) use (&$processes, $directory, $database, $nonce, $original): array {
    $batch = bin2hex(random_bytes(4));
    $stateFile = $directory.'/'.$batch.'.json';
    $named = [];
    foreach ($jobs as $index => $job) {
        $named[$batch.'-'.$index] = $job;
    }
    writeJson($stateFile, ['database' => $database, 'nonce' => $nonce, 'jobs' => $named]);
    $controlConfig = $original;
    $controlConfig['url'] = null;
    $controlConfig['database'] = $database;
    config(['database.connections.admission_verification_control' => $controlConfig]);
    DB::purge('admission_verification_control');
    $control = DB::connection('admission_verification_control');
    if ($lockedUser !== null) {
        $control->beginTransaction();
        $control->table('users')->where('id', $lockedUser)->lockForUpdate()->first();
    }
    $batchProcesses = [];
    try {
        foreach ($named as $id => $job) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker', $stateFile, $id], dirname(__DIR__), timeout: 60);
            $process->start();
            $processes[] = $process;
            $batchProcesses[$id] = $process;
        }
        waitFor(function () use ($named, $directory): bool {
            foreach ($named as $id => $job) {
                if (! file_exists($directory.'/'.$id.'.ready')) {
                    return false;
                }
            }

            return true;
        }, 'workers ready');
        touch($stateFile.'.go');
        if ($lockedUser !== null) {
            $ids = array_map(fn ($id) => readJson($directory.'/'.$id.'.ready')['connection_id'], array_keys($named));
            // Both actual SQL statements must be in flight while the parent owns the row lock.
            waitFor(fn () => $control->table('information_schema.PROCESSLIST')->whereIn('ID', $ids)
                ->where('INFO', 'like', '%for update%')->count() === count($ids), 'both workers contending on user lock');
            $control->commit();
        }
        $results = [];
        foreach ($batchProcesses as $id => $process) {
            $process->wait();
            verify($process->isSuccessful() && file_exists($directory.'/'.$id.'.result'), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
            $results[] = readJson($directory.'/'.$id.'.result');
        }

        return $results;
    } finally {
        if ($control->transactionLevel() > 0) {
            $control->rollBack();
        }
    }
};

try {
    try {
        $report['isolation_level'] = DB::selectOne('SELECT @@transaction_isolation AS level')->level;
    } catch (QueryException $exception) {
        if (($exception->errorInfo[1] ?? null) !== 1193) {
            throw $exception;
        }
        $report['isolation_level'] = DB::selectOne('SELECT @@tx_isolation AS level')->level;
    }
    $guestRole = Role::query()->where('role_name', Role::GUEST)->firstOrFail();
    $adminRole = Role::query()->where('role_name', Role::ADMIN)->firstOrFail();
    $userCounter = 0;
    $user = function (?int $roleId = null) use (&$userCounter, $guestRole, &$userIds, $ledger, $nonce): User {
        $account = User::query()->create(['username' => 'av-'.$nonce.'-'.++$userCounter, 'password' => bin2hex(random_bytes(24)),
            'role_id' => $roleId ?? $guestRole->id, 'status' => 'active', 'is_first_login' => true]);
        $userIds[] = $account->id;
        $ledger();
        $account->profile()->create(['first_name' => 'Isolated', 'last_name' => 'Verification', 'gender' => 'Prefer not to say']);

        return $account;
    };
    $admin = $user($adminRole->id);
    $year = AcademicYear::query()->create(['school_year' => 'AV-'.$nonce, 'start_date' => '2098-06-01', 'end_date' => '2099-05-31', 'status' => 'active']);
    $yearId = $year->id;
    $ledger();
    $cycleCounter = 0;
    $cycle = function () use (&$cycleCounter, $admin, $year, &$cycleIds, $ledger, $nonce): AdmissionCycle {
        $intake = AdmissionCycle::query()->create(['code' => 'AV'.$nonce.'-'.++$cycleCounter, 'name' => 'Isolated verification', 'academic_year_id' => $year->id,
            'status' => AdmissionCycle::OPEN, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'confirmation_closes_at' => now()->addDays(2),
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);
        $cycleIds[] = $intake->id;
        $ledger();

        return $intake;
    };
    $endpointCount = 0;
    if (AdmissionCycle::query()->where('status', AdmissionCycle::OPEN)->where('opens_at', '<=', now())->where('closes_at', '>', now())->exists()) {
        $report['endpoint_races'] = 'skipped: existing open cycle; never modify real cycle configuration';
    } else {
        $report['endpoint_races'] = [];
        for ($round = 0; $round < 3; $round++) {
            $account = $user();
            $intake = $cycle();
            $job = ['user_id' => $account->id, 'cycle_id' => $intake->id, 'endpoint' => true];
            $results = $run([$job, $job], $account->id);
            $statuses = array_column($results, 'status');
            sort($statuses);
            verify($statuses === [201, 409], 'Endpoint race did not return 201 and 409');
            verify(collect($results)->firstWhere('status', 409)['code'] === 'application_exists', 'Unsafe endpoint conflict');
            verify(AdmissionApplicant::query()->where('user_id', $account->id)->count() === 1, 'Endpoint duplicate row');
            $report['endpoint_races'][] = ['statuses' => $statuses, 'overlapping_lock_waits_verified' => true];
            $endpointCount++;
            $intake->update(['status' => AdmissionCycle::CLOSED]);
        }
    }
    $report['same_user_races'] = [];
    for ($round = 0; $round < 3; $round++) {
        $account = $user();
        $intake = $cycle();
        $job = ['user_id' => $account->id, 'cycle_id' => $intake->id];
        $results = $run([$job, $job], $account->id);
        $statuses = array_column($results, 'status');
        sort($statuses);
        verify($statuses === ['created', 'validation'], 'Same-user race did not yield one clean loser');
        $loser = collect($results)->firstWhere('status', 'validation');
        verify(($loser['errors']['cycle'][0] ?? '') === 'An application already exists for this cycle.', 'Unexpected competing operation rejection');
        verify(AdmissionApplicant::query()->where('user_id', $account->id)->count() === 1, 'Same-user duplicate row');
        $report['same_user_races'][] = ['statuses' => $statuses, 'overlapping_lock_waits_verified' => true];
    }
    // Separate cycles avoid the intentional same-cycle lock serializing all allocators.
    $jobs = [];
    for ($i = 0; $i < 4; $i++) {
        $jobs[] = ['user_id' => $user()->id, 'cycle_id' => $cycle()->id];
    }
    $results = $run($jobs);
    verify(array_column($results, 'status') === array_fill(0, 4, 'created'), 'Different-user allocation failed');
    verify(count(array_unique(array_column($results, 'number'))) === 4, 'Different-user duplicate number');
    $report['different_users'] = ['created' => 4, 'unique_numbers' => 4];

    $forcedUuid = (string) Uuid::uuid4();
    $results = $run([
        ['user_id' => $user()->id, 'cycle_id' => $cycle()->id, 'uuid' => $forcedUuid],
        ['user_id' => $user()->id, 'cycle_id' => $cycle()->id, 'uuid' => $forcedUuid],
    ]);
    verify(array_column($results, 'status') === ['created', 'created'], 'Forced collision escaped instead of retrying');
    $attempts = array_column($results, 'number_attempts');
    sort($attempts);
    verify($attempts === [1, 2] && count(array_unique(array_column($results, 'number'))) === 2, 'Collision retry result incorrect');
    $report['forced_number_collision'] = ['created' => 2, 'attempts' => $attempts, 'unique_numbers' => 2];

    $exhaustedUser = $user();
    $result = $run([['user_id' => $exhaustedUser->id, 'cycle_id' => $cycle()->id, 'uuid' => $forcedUuid, 'exhaust' => true]])[0];
    verify($result['status'] === 'unique_constraint' && $result['number_attempts'] === 3, 'Collision retries not bounded to three');
    verify(! AdmissionApplicant::query()->where('user_id', $exhaustedUser->id)->exists(), 'Exhausted collision left partial row');
    $report['retry_exhaustion'] = $result;

    $rollbackUser = $user();
    $beforeAudit = AdmissionAuditEvent::query()->count();
    $result = $run([['user_id' => $rollbackUser->id, 'cycle_id' => $cycle()->id, 'rollback' => true]])[0];
    verify($result['status'] === 'error' && $result['message'] === 'Injected failure after audit insertion', 'Rollback injection failed');
    verify(! AdmissionApplicant::query()->where('user_id', $rollbackUser->id)->exists(), 'Rollback left applicant');
    verify(AdmissionAuditEvent::query()->count() === $beforeAudit, 'Rollback left audit');
    $report['rollback'] = ['applicant_rows' => 0, 'audit_rows' => 0];

    // Verify database constraints independently of the service's ownership check.
    $existing = AdmissionApplicant::query()->whereIn('user_id', $userIds)->firstOrFail();
    foreach ([['user_id' => $existing->user_id, 'applicant_number' => 'APP-'.Uuid::uuid4()],
        ['user_id' => $user()->id, 'applicant_number' => $existing->applicant_number]] as $duplicate) {
        try {
            DB::transaction(fn () => DB::table('admission_applicants')->insert([...$duplicate, 'cycle_id' => $existing->cycle_id]));
            throw new RuntimeException('Database accepted duplicate');
        } catch (UniqueConstraintViolationException) {
            // Expected: neither ownership nor number uniqueness relies only on service checks.
        }
    }
    $report['database_unique_constraints'] = 'passed';
    verify(AdmissionApplicant::query()->whereIn('user_id', $userIds)->count() === 9 + $endpointCount, 'Unexpected total applicants');
    verify(AdmissionAuditEvent::query()->whereIn('actor_user_id', $userIds)->count() === 9 + $endpointCount, 'Unexpected total audit events');
    foreach (AdmissionApplicant::query()->whereIn('user_id', $userIds)->get() as $applicant) {
        verify($applicant->status === 'draft' && $applicant->converted_student_id === null && $applicant->converted_at === null, 'Invalid applicant row');
        $event = AdmissionAuditEvent::query()->where('applicant_id', $applicant->id)->sole();
        verify($event->actor_user_id === $applicant->user_id && $event->action === AdmissionAuditEvent::IDENTITY_CREATED, 'Invalid audit linkage');
        verify($event->metadata === ['cycle_id' => $applicant->cycle_id, 'status' => 'draft', 'version' => 1], 'Unexpected audit metadata');
    }
    $report['atomic_audit'] = ['applicants' => 9 + $endpointCount, 'events' => 9 + $endpointCount];
    $report['result'] = 'passed';
} catch (Throwable $exception) {
    $report['result'] = 'failed';
    $report['error'] = $exception::class.': '.$exception->getMessage();
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    DB::purge('admission_verification_control');
    try {
        DB::transaction(function () use ($userIds, $cycleIds, $yearId): void {
            $applicantIds = DB::table('admission_applicants')->whereIn('user_id', $userIds)->pluck('id');
            // Explicit test cleanup bypasses append-only models, scoped to this run's IDs.
            DB::table('admission_audit_events')->whereIn('applicant_id', $applicantIds)->delete();
            DB::table('admission_applicants')->whereIn('id', $applicantIds)->delete();
            DB::table('admission_cycles')->whereIn('id', $cycleIds)->delete();
            DB::table('user_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            if ($yearId !== null) {
                DB::table('academic_years')->where('id', $yearId)->delete();
            }
        });
        $report['cleanup'] = 'passed';
        $report['cleaned_fixtures'] = ['users' => count($userIds), 'profiles' => count($userIds), 'cycles' => count($cycleIds), 'academic_years' => $yearId === null ? 0 : 1];
        $after = databaseFingerprint();
        $report['existing_schema_and_data_unchanged'] = $before === $after;
        verify($before === $after, 'Database fingerprints differ after cleanup; inspect concurrent activity before claiming preservation');
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    } catch (Throwable $exception) {
        $report['result'] = 'failed';
        $report['cleanup_error'] = $exception->getMessage();
        $report['fixture_ledger'] = $directory.'/fixtures.json';
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit(($report['result'] === 'passed' && ($report['cleanup'] ?? '') === 'passed') ? 0 : 1);

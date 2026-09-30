<?php

// Manual non-production verifier: exact-ID fixtures, no migrations, complete cleanup and fingerprints.
use App\Models\Admission\AdmissionApplicant;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Admission\AdmissionConversionService;
use App\Services\StepUpAuthenticationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\Support\AdmissionConversionFixture;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function fingerprint(): array
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
        $snapshot[$table->name] = [preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl), count($hashes), hash('sha256', implode('', $hashes))];
    }

    return $snapshot;
}
check(! $app->environment('production'), 'Production is forbidden.');
check(DB::connection()->getDriverName() === 'mysql', 'MySQL/MariaDB is required.');
if (($argv[1] ?? '') === '--worker') {
    $file = $argv[2];
    $index = (int) $argv[3];
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    check(DB::selectOne('SELECT DATABASE() AS name')->name === $state['database'], 'Database mismatch.');
    $actor = User::findOrFail($state['registrar']);
    check($actor->username === 'cv-'.$state['nonce'].'-registrar', 'Not a verifier account.');
    $job = $state['jobs'][$index];
    $applicant = AdmissionApplicant::findOrFail($job['id']);
    check(str_starts_with($applicant->user->username, 'cv-'.$state['nonce'].'-'), 'Not a fixture applicant.');
    config(['cache.default' => 'array']);
    Sanctum::actingAs($actor);
    $request = Request::create('/api/admission/registrar/applicants/'.$job['id'].'/convert', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode($job['payload']));
    $request->setUserResolver(fn () => $actor);
    app(StepUpAuthenticationService::class)->verify($request, 'password');
    file_put_contents($file.'.ready'.$index, 'ready');
    $deadline = microtime(true) + 30;
    while (! file_exists($file.'.go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    check(file_exists($file.'.go'), 'Start gate timed out.');
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);
    echo json_encode(['status' => $response->getStatusCode(), 'student_id' => $body['data']['student']['student_id'] ?? null, 'already' => $body['data']['already_converted'] ?? null]);
    $kernel->terminate($request, $response);
    exit;
}
check(($argv[1] ?? '') === '--run-with-fixtures', 'Opt in with --run-with-fixtures.');
foreach ([Role::GUEST, Role::STUDENT, Role::REGISTRAR_STAFF] as $role) {
    check(Role::where('role_name', $role)->exists(), 'Required role missing: '.$role);
}
$before = fingerprint();
$nonce = bin2hex(random_bytes(3));
$directory = sys_get_temp_dir().'/admission_conversion_'.$nonce;
check(mkdir($directory, 0700), 'Cannot create private fixture directory.');
$fixture = null;
$processes = [];
try {
    $fixture = DB::transaction(fn () => AdmissionConversionFixture::create($nonce));
    $service = app(AdmissionConversionService::class);
    $jobs = [];
    foreach ($fixture['cases'] as $index => $case) {
        $payload = ['version' => 1, 'result_id' => $case['result']->id, 'result_version' => 1, 'course_id' => $fixture['course']->id,
            'curriculum_id' => $fixture['curriculum']->id, 'student_number' => 'CV-'.$nonce.($index === 0 ? '-A' : '-B'), 'admission_date' => now()->toDateString(), 'confirmed' => true];
        $accepted = $service->accept($fixture['registrar'], $case['applicant']->id, $payload);
        $payload['version'] = $accepted['version'];
        $jobs[] = ['id' => $case['applicant']->id, 'payload' => $payload];
    }
    // Persist only synthetic IDs and input, never credentials. Enables exact recovery if externally interrupted.
    file_put_contents($directory.'/ledger.json', json_encode(['nonce' => $nonce, 'registrar' => $fixture['registrar']->id,
        'users' => array_map(fn ($c) => $c['user']->id, $fixture['cases']), 'applicants' => array_column($jobs, 'id'),
        'cycle' => $fixture['cycle']->id, 'year' => $fixture['year']->id, 'course' => $fixture['course']->id,
        'curriculum' => $fixture['curriculum']->id, 'department' => $fixture['department']->id]));
    // Two confirmations for one applicant, plus two other applicants competing for another official number.
    $state = ['database' => DB::selectOne('SELECT DATABASE() AS name')->name, 'nonce' => $nonce, 'registrar' => $fixture['registrar']->id, 'jobs' => [$jobs[0], $jobs[0], $jobs[1], $jobs[2]]];
    $file = $directory.'/race.json';
    file_put_contents($file, json_encode($state));
    DB::beginTransaction();
    User::whereIn('id', array_map(fn ($c) => $c['user']->id, $fixture['cases']))->orderBy('id')->lockForUpdate()->get();
    foreach ($state['jobs'] as $index => $job) {
        $process = new Process([PHP_BINARY, __FILE__, '--worker', $file, (string) $index]);
        $process->setTimeout(45);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    do {
        $ready = count(glob($file.'.ready*')) === 4;
        if (! $ready) {
            usleep(10000);
        }
    } while (! $ready && microtime(true) < $deadline);
    check($ready, 'Workers did not become ready.');
    file_put_contents($file.'.go', 'go');
    usleep(150000);
    DB::commit();
    $responses = [];
    foreach ($processes as $process) {
        $process->wait();
        check($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
        $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    $students = Student::whereIn('user_id', array_map(fn ($c) => $c['user']->id, $fixture['cases']))->get();
    check($students->count() === 2, 'Race created an unexpected number of Students.');
    check($responses[0]['status'] === 200 && $responses[1]['status'] === 200 && $responses[0]['student_id'] === $responses[1]['student_id'], 'Same-applicant confirmations were not idempotent.');
    $statuses = [$responses[2]['status'], $responses[3]['status']];
    sort($statuses);
    check($statuses === [200, 409], 'Competing official numbers must produce one success and one conflict.');
    $loserIndex = $responses[2]['status'] === 409 ? 1 : 2;
    check($fixture['cases'][$loserIndex]['user']->fresh('role')->role->role_name === Role::GUEST, 'Collision changed losing role.');
    check(DB::table('admission_decisions')->whereIn('applicant_id', array_column($jobs, 'id'))->where('action', 'student_converted')->count() === 2, 'Duplicate conversion evidence.');
    echo json_encode(['race' => $responses, 'two_unique_students' => true, 'idempotent_race' => true, 'loser_remains_guest' => true]).PHP_EOL;
} finally {
    while (DB::transactionLevel()) {
        DB::rollBack();
    }
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    if ($fixture) {
        DB::transaction(function () use ($fixture) {
            $ids = array_map(fn ($c) => $c['applicant']->id, $fixture['cases']);
            $userIds = array_map(fn ($c) => $c['user']->id, $fixture['cases']);
            $sessionIds = array_map(fn ($c) => $c['session']->id, $fixture['cases']);
            DB::table('admission_workflow_events')->whereIn('applicant_id', $ids)->delete();
            DB::table('admission_decisions')->whereIn('applicant_id', $ids)->delete();
            DB::table('admission_exam_results')->whereIn('session_id', $sessionIds)->delete();
            DB::table('admission_exam_sessions')->whereIn('id', $sessionIds)->delete();
            DB::table('admission_applicants')->whereIn('id', $ids)->delete();
            DB::table('students')->whereIn('user_id', $userIds)->delete();
            DB::table('user_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('admission_cycles')->where('id', $fixture['cycle']->id)->delete();
            DB::table('users')->where('id', $fixture['registrar']->id)->delete();
            DB::table('curriculums')->where('id', $fixture['curriculum']->id)->delete();
            DB::table('courses')->where('id', $fixture['course']->id)->delete();
            DB::table('departments')->where('id', $fixture['department']->id)->delete();
            DB::table('academic_years')->where('id', $fixture['year']->id)->delete();
        });
    }
    check($before === fingerprint(), 'Schema/data fingerprint mismatch after cleanup. Inspect '.$directory.'/ledger.json');
    echo "Fixture cleanup and original schema/data fingerprints passed (auto-increment counters excluded).\n";
}

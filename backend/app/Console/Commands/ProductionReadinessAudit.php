<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductionReadinessAudit extends Command
{
    protected $signature = 'portal:production-audit {--json : Emit machine-readable JSON} {--strict : Return failure when required checks fail}';

    protected $description = 'Read-only production configuration, account, queue, storage, and fixture inventory';

    private const FIXTURE_USERNAMES = [
        'ken', 'admin', 'registrar1', 'professor1', 'student1',
    ];

    public function handle(): int
    {
        $report = [
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'runtime' => $this->runtime(),
            'checks' => [],
            'database' => ['connected' => false],
            'manual_verification_required' => [
                'Current database backup completed and restoration tested in an isolated environment.',
                'HTTPS certificate, reverse proxy, Cloudflare Tunnel, queue worker, and scheduler checked on the deployed host.',
                'SMTP tested with an explicitly approved non-production recipient.',
            ],
        ];
        $report['checks'] = $this->checks();

        try {
            DB::select('SELECT 1');
            $report['database'] = $this->databaseInventory();
        } catch (Throwable $exception) {
            $report['database'] = [
                'connected' => false,
                'error' => 'Database connection failed (details withheld).',
                'error_class' => $exception::class,
            ];
            $report['checks'][] = $this->check('database_connection', false, 'The configured database is unreachable.');
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderReport($report);
        }

        $failed = collect($report['checks'])->contains(fn (array $check): bool => $check['status'] === 'fail');

        return $this->option('strict') && $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function runtime(): array
    {
        return [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'app_url_host' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'frontend_url_host' => parse_url((string) config('app.frontend_url'), PHP_URL_HOST),
            'php' => PHP_VERSION,
            'database_driver' => config('database.default'),
            'session_driver' => config('session.driver'),
            'queue_driver' => config('queue.default'),
            'mail_driver' => config('mail.default'),
            'cache_driver' => config('cache.default'),
        ];
    }

    /** @return array<int, array{name:string,status:string,message:string}> */
    private function checks(): array
    {
        $appUrl = (string) config('app.url');
        $frontendUrl = (string) config('app.frontend_url');
        $cors = config('cors.allowed_origins', []);

        return [
            $this->check('production_environment', app()->environment('production'), 'APP_ENV must be production.'),
            $this->check('debug_disabled', ! config('app.debug'), 'APP_DEBUG must be false.'),
            $this->check('https_app_url', str_starts_with($appUrl, 'https://'), 'APP_URL must use HTTPS.'),
            $this->check('https_frontend_url', str_starts_with($frontendUrl, 'https://'), 'FRONTEND_URL must use HTTPS.'),
            $this->check('secure_session_cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE must be true.'),
            $this->check('persistent_session', config('session.driver') !== 'array', 'Use a persistent session driver.'),
            $this->check('async_queue', ! in_array(config('queue.default'), ['sync', 'null'], true), 'Use an asynchronous queue worker.'),
            $this->check('real_mail_transport', ! in_array(config('mail.default'), ['array', 'log'], true), 'Configure a real mail transport.'),
            $this->check('explicit_cors', is_array($cors) && $cors !== [] && ! in_array('*', $cors, true), 'Set explicit allowed frontend origins.'),
            $this->check('storage_writable', is_writable(storage_path()) && is_writable(storage_path('framework')) && is_writable(storage_path('logs')), 'Laravel storage paths must be writable.'),
            $this->check('public_storage_link', is_link(public_path('storage')), 'Create the public storage link when public uploads are enabled.'),
        ];
    }

    /** @return array<string, mixed> */
    private function databaseInventory(): array
    {
        $roles = DB::table('roles')
            ->leftJoin('users', 'users.role_id', '=', 'roles.id')
            ->groupBy('roles.role_name')
            ->selectRaw('roles.role_name, COUNT(users.id) aggregate')
            ->pluck('aggregate', 'role_name')
            ->all();
        $statuses = DB::table('users')->groupBy('status')->selectRaw('status, COUNT(*) aggregate')->pluck('aggregate', 'status')->all();
        $fixtures = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('users.username', self::FIXTURE_USERNAMES)
            ->orderBy('users.username')
            ->get(['users.id', 'users.username', 'roles.role_name'])
            ->map(fn (object $user): array => [
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role_name,
                'dependencies' => $this->dependencies((int) $user->id),
            ])->all();

        return [
            'connected' => true,
            'roles' => $roles,
            'account_statuses' => $statuses,
            'fixture_name_candidates' => $fixtures,
            'queued_jobs' => $this->tableCount('jobs'),
            'failed_jobs' => $this->tableCount('failed_jobs'),
            'sessions' => $this->tableCount('sessions'),
            'generated_fixture_ledger_rows' => $this->tableCount('generated_data_records'),
            'note' => 'Fixture-name matches are cleanup candidates only. Review dependencies and ownership before deleting anything.',
        ];
    }

    /** @return array<string, int> */
    private function dependencies(int $userId): array
    {
        $counts = [];
        foreach ([
            'user_profiles' => ['user_id', $userId],
            'students' => ['user_id', $userId],
            'professors' => ['user_id', $userId],
            'registrar_staff' => ['user_id', $userId],
            'admission_applicants' => ['user_id', $userId],
        ] as $table => [$column, $value]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $counts[$table] = DB::table($table)->where($column, $value)->count();
            }
        }

        if (Schema::hasTable('personal_access_tokens')) {
            $counts['personal_access_tokens'] = DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('tokenable_id', $userId)
                ->count();
        }

        $studentId = Schema::hasTable('students') ? DB::table('students')->where('user_id', $userId)->value('id') : null;
        if ($studentId) {
            foreach (['enrollments', 'enrollment_applications', 'student_documents', 'document_requests', 'appointments', 'student_record_locations'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'student_id')) {
                    $counts[$table] = DB::table($table)->where('student_id', $studentId)->count();
                }
            }
        }

        return array_filter($counts);
    }

    private function tableCount(string $table): ?int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : null;
    }

    /** @return array{name:string,status:string,message:string} */
    private function check(string $name, bool $passed, string $message): array
    {
        return ['name' => $name, 'status' => $passed ? 'pass' : 'fail', 'message' => $passed ? 'OK' : $message];
    }

    /** @param array<string, mixed> $report */
    private function renderReport(array $report): void
    {
        $this->info('CDM Portal production readiness audit (read-only)');
        $this->table(['Check', 'Status', 'Message'], array_map(
            fn (array $check): array => [$check['name'], strtoupper($check['status']), $check['message']],
            $report['checks'],
        ));
        $this->line('Runtime: '.json_encode($report['runtime'], JSON_UNESCAPED_SLASHES));
        $this->line('Database inventory: '.json_encode($report['database'], JSON_UNESCAPED_SLASHES));
        $this->warn('No records were modified. Backup/restore and host-service checks still require operator verification.');
    }
}

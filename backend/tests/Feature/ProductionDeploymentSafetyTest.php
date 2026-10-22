<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LargeDatasetCleanupSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class ProductionDeploymentSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_database_seeder_creates_only_configured_bootstrap_staff_in_production(): void
    {
        $this->bootstrapConfiguration();
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            app(DatabaseSeeder::class)->run();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('user_profiles', 2);
        $this->assertDatabaseCount('registrar_staff', 1);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('professors', 0);
        $this->assertDatabaseCount('student_documents', 0);
        $this->assertDatabaseCount('student_record_locations', 0);
        $this->assertDatabaseHas('roles', ['role_name' => 'Student']);
        $this->assertDatabaseHas('document_types', ['document_name' => 'Transcript of Records']);
        $this->assertDatabaseHas('users', ['username' => 'production.admin', 'status' => 'active', 'is_first_login' => true]);
        $this->assertDatabaseHas('users', ['username' => 'production.registrar', 'status' => 'active', 'is_first_login' => true]);
        $this->assertTrue(Hash::check('Admin-Bootstrap-123!', DB::table('users')->where('username', 'production.admin')->value('password')));
    }

    public function test_production_seed_is_idempotent_and_does_not_reset_existing_credentials_or_configuration(): void
    {
        $this->bootstrapConfiguration();
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            app(DatabaseSeeder::class)->run();
            $password = DB::table('users')->where('username', 'production.admin')->value('password');
            DB::table('departments')->where('department_code', 'ICS')->update(['description' => 'Institution-approved description']);
            config()->set('production_bootstrap.admin.password', 'Different-Bootstrap-456!');
            app(DatabaseSeeder::class)->run();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('registrar_staff', 1);
        $this->assertSame($password, DB::table('users')->where('username', 'production.admin')->value('password'));
        $this->assertSame('Institution-approved description', DB::table('departments')->where('department_code', 'ICS')->value('description'));
    }

    public function test_explicit_development_identity_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            app(UsersSeeder::class)->run();
            $this->fail('Development identity seeding should have been refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('restricted to local and testing', $exception->getMessage());
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_large_dataset_cleanup_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            app(LargeDatasetCleanupSeeder::class)->run();
            $this->fail('Production cleanup should have been refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled in the production environment', $exception->getMessage());
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_known_proxy_supplies_https_without_overriding_the_request_host(): void
    {
        config()->set('trustedproxy.proxies', ['127.0.0.1']);
        Route::get('/_production-proxy-check', fn (Request $request): array => [
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);

        $response = $this->call('GET', '/_production-proxy-check', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'portal.example.edu',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example.test',
        ]);

        $response->assertOk()->assertJsonPath('secure', true);
        $this->assertNotSame('untrusted.example.test', $response->json('host'));
    }

    public function test_queue_retry_windows_exceed_document_analysis_timeout(): void
    {
        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan(120, config("queue.connections.{$connection}.retry_after"));
        }
    }

    public function test_production_audit_is_read_only_and_redacts_configuration_secrets(): void
    {
        config()->set('services.monitoring_ai.api_key', 'must-not-appear');
        $before = DB::table('users')->count();

        $this->artisan('portal:production-audit', ['--json' => true])
            ->expectsOutputToContain('"read_only": true')
            ->doesntExpectOutputToContain('must-not-appear')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('users')->count());
    }

    private function bootstrapConfiguration(): void
    {
        config()->set('production_bootstrap', [
            'enabled' => true,
            'admin' => [
                'username' => 'production.admin',
                'password' => 'Admin-Bootstrap-123!',
                'email' => 'production.admin@example.test',
                'first_name' => 'Production',
                'last_name' => 'Administrator',
            ],
            'registrar' => [
                'username' => 'production.registrar',
                'password' => 'Registrar-Bootstrap-123!',
                'email' => 'production.registrar@example.test',
                'first_name' => 'Production',
                'last_name' => 'Registrar',
                'employee_number' => 'PROD-REG-001',
            ],
        ]);
    }
}

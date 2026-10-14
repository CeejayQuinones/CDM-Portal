<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LargeDatasetCleanupSeeder;
use Illuminate\Http\Request;
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

    public function test_database_seeder_excludes_demo_identities_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            app(DatabaseSeeder::class)->run();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('professors', 0);
        $this->assertDatabaseCount('student_documents', 0);
        $this->assertDatabaseCount('student_record_locations', 0);
        $this->assertDatabaseHas('roles', ['role_name' => 'Student']);
        $this->assertDatabaseHas('document_types', ['document_name' => 'Transcript of Records']);
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
}

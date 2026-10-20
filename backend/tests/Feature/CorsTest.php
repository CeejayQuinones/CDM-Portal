<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

class CorsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const PROTECTED_ENDPOINTS = [
        '/api/me',
        '/api/registrar/dashboard',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cors.allowed_origins' => [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'https://localhost',
            ],
        ]);
    }

    public function test_android_capacitor_origin_can_preflight_the_login_endpoint(): void
    {
        $response = $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => 'https://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://localhost');

        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods', ''));
        $this->assertStringContainsString('content-type', strtolower($response->headers->get('Access-Control-Allow-Headers', '')));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_protected_api_endpoints_accept_preflight_from_local_frontends(): void
    {
        foreach (self::PROTECTED_ENDPOINTS as $endpoint) {
            foreach (config('cors.allowed_origins') as $origin) {
                $response = $this->call('OPTIONS', $endpoint, server: [
                    'HTTP_ORIGIN' => $origin,
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                    'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
                ]);

                $response
                    ->assertNoContent()
                    ->assertHeader('Access-Control-Allow-Origin', $origin);
            }
        }
    }

    public function test_unlisted_origins_do_not_receive_cors_permission(): void
    {
        $this->call('OPTIONS', '/api/me', server: [
            'HTTP_ORIGIN' => 'https://untrusted.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_production_origins_remain_explicitly_configured(): void
    {
        $cors = $this->loadCorsConfig('production', 'https://portal.cdm.edu.ph, https://api-client.cdm.edu.ph');

        $this->assertSame([
            'https://portal.cdm.edu.ph',
            'https://api-client.cdm.edu.ph',
        ], $cors['allowed_origins']);
        $this->assertSame([], $cors['allowed_origins_patterns']);
        $this->assertNotContains('*', $cors['allowed_origins']);
        $this->assertNotContains('https://localhost', $cors['allowed_origins']);
    }

    public function test_production_has_no_implicit_cors_origins(): void
    {
        $cors = $this->loadCorsConfig('production', null);

        $this->assertSame([], $cors['allowed_origins']);
        $this->assertSame([], $cors['allowed_origins_patterns']);
    }

    public function test_cors_does_not_bypass_authentication_on_normal_requests(): void
    {
        foreach (self::PROTECTED_ENDPOINTS as $endpoint) {
            $this->withHeader('Origin', 'http://localhost:5173')
                ->getJson($endpoint)
                ->assertUnauthorized()
                ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        }
    }

    /** @return array<string, mixed> */
    private function loadCorsConfig(string $environment, ?string $origins): array
    {
        $repository = Env::getRepository();
        $previousEnvironment = $repository->get('APP_ENV');
        $previousOrigins = $repository->get('CORS_ALLOWED_ORIGINS');

        try {
            $repository->set('APP_ENV', $environment);
            $origins === null
                ? $repository->clear('CORS_ALLOWED_ORIGINS')
                : $repository->set('CORS_ALLOWED_ORIGINS', $origins);

            return require config_path('cors.php');
        } finally {
            $previousEnvironment === null
                ? $repository->clear('APP_ENV')
                : $repository->set('APP_ENV', $previousEnvironment);
            $previousOrigins === null
                ? $repository->clear('CORS_ALLOWED_ORIGINS')
                : $repository->set('CORS_ALLOWED_ORIGINS', $previousOrigins);
        }
    }
}

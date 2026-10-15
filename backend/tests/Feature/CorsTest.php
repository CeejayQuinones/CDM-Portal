<?php

namespace Tests\Feature;

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
            ],
        ]);
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

    public function test_cors_does_not_bypass_authentication_on_normal_requests(): void
    {
        foreach (self::PROTECTED_ENDPOINTS as $endpoint) {
            $this->withHeader('Origin', 'http://localhost:5173')
                ->getJson($endpoint)
                ->assertUnauthorized()
                ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        }
    }
}

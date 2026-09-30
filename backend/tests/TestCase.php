<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\PersonalAccessToken;

abstract class TestCase extends BaseTestCase
{
    /**
     * Existing feature tests predate the required client header. Supply the
     * role's normal client unless a platform test explicitly disables it.
     */
    protected bool $injectClientPlatformHeader = true;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->injectClientPlatformHeader
            && str_starts_with(parse_url((string) $uri, PHP_URL_PATH) ?: '', '/api/')
            && ! array_key_exists('HTTP_X_CDM_CLIENT', $server)) {
            $server['HTTP_X_CDM_CLIENT'] = $this->defaultClientPlatform($content);
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function withoutAutomaticClientPlatform(): static
    {
        $this->injectClientPlatformHeader = false;

        return $this;
    }

    private function defaultClientPlatform(?string $content): string
    {
        $authorization = $this->defaultHeaders['Authorization'] ?? null;
        $user = null;

        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $token = PersonalAccessToken::findToken(substr($authorization, 7));
            $user = $token?->tokenable;
        }

        $user ??= app('auth')->guard('sanctum')->user();

        if (! $user instanceof User && is_string($content)) {
            $payload = json_decode($content, true);
            $username = is_array($payload) ? ($payload['username'] ?? null) : null;
            $user = is_string($username) ? User::query()->where('username', $username)->with('role')->first() : null;
        }

        $role = $user instanceof User ? $user->loadMissing('role')->role?->role_name : null;

        return $role === Role::REGISTRAR_STAFF ? ClientPlatform::DESKTOP : ClientPlatform::WEB;
    }
}

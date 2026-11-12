<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TurnstileVerificationService
{
    private const TEST_SITE_KEYS = [
        '1x00000000000000000000AA',
        '2x00000000000000000000AB',
        '1x00000000000000000000BB',
        '2x00000000000000000000BB',
        '3x00000000000000000000FF',
    ];

    private const TEST_SECRET_KEYS = [
        '1x0000000000000000000000000000000AA',
        '2x0000000000000000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    ];

    public function verify(?string $token, string $expectedAction, string $ipAddress): void
    {
        if (! config('services.turnstile.enabled')) {
            if (app()->environment('production')) {
                throw new RuntimeException('Cloudflare Turnstile must be enabled in production.');
            }

            return;
        }

        $secret = trim((string) config('services.turnstile.secret_key'));
        $siteKey = trim((string) config('services.turnstile.site_key'));
        $hostnames = config('services.turnstile.expected_hostnames', []);

        if ($secret === '' || $siteKey === '' || ! is_array($hostnames) || $hostnames === []) {
            throw new RuntimeException('Cloudflare Turnstile configuration is incomplete.');
        }

        $usesTestCredentials = in_array($siteKey, self::TEST_SITE_KEYS, true)
            && in_array($secret, self::TEST_SECRET_KEYS, true);

        if (app()->environment('production')
            && (in_array($siteKey, self::TEST_SITE_KEYS, true) || in_array($secret, self::TEST_SECRET_KEYS, true))) {
            throw new RuntimeException('Cloudflare Turnstile testing credentials cannot be used in production.');
        }

        if (! is_string($token) || trim($token) === '' || strlen($token) > 2048) {
            $this->reject('Please complete the human verification check.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout((int) config('services.turnstile.connect_timeout', 3))
                ->timeout((int) config('services.turnstile.timeout', 6))
                ->post((string) config('services.turnstile.endpoint'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ]);
        } catch (ConnectionException) {
            $this->reject('Human verification is temporarily unavailable. Please try again.');
        }

        if (! $response->successful()) {
            $this->reject('Human verification is temporarily unavailable. Please try again.');
        }

        $result = $response->json();
        if (! is_array($result) || ! array_key_exists('success', $result) || ! is_bool($result['success'])) {
            $this->reject('Human verification could not be confirmed. Please try again.');
        }

        if ($result['success'] !== true) {
            $codes = is_array($result['error-codes'] ?? null) ? $result['error-codes'] : [];
            $message = in_array('timeout-or-duplicate', $codes, true)
                ? 'Human verification expired or was already used. Please try again.'
                : 'Human verification could not be confirmed. Please try again.';
            $this->reject($message);
        }

        if (! $usesTestCredentials) {
            $hostname = strtolower(trim((string) ($result['hostname'] ?? '')));
            if ($hostname === '' || ! in_array($hostname, $hostnames, true)) {
                $this->reject('Human verification was completed for an unexpected site. Please try again.');
            }

            $action = trim((string) ($result['action'] ?? ''));
            if ($expectedAction !== '' && ! hash_equals($expectedAction, $action)) {
                $this->reject('Human verification did not match this request. Please try again.');
            }
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'turnstile_token' => [$message],
        ]);
    }
}

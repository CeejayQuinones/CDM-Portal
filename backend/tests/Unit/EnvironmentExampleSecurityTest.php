<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EnvironmentExampleSecurityTest extends TestCase
{
    public function test_backend_environment_example_contains_no_secret_values(): void
    {
        $environment = $this->parseEnvironmentFile(dirname(__DIR__, 2).'/.env.example');

        foreach ([
            'APP_KEY',
            'DB_PASSWORD',
            'GEMINI_API_KEY',
            'MONITORING_AI_API_KEY',
            'MAIL_PASSWORD',
            'ADMISSION_GEMINI_API_KEY',
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
        ] as $key) {
            $this->assertSame('', $environment[$key] ?? null, "{$key} must remain empty in backend/.env.example.");
        }

        $this->assertContains(
            $environment['REDIS_PASSWORD'] ?? null,
            ['', 'null'],
            'REDIS_PASSWORD must remain empty or null in backend/.env.example.',
        );
    }

    public function test_backend_environment_example_uses_nonpersonal_mail_placeholders(): void
    {
        $environment = $this->parseEnvironmentFile(dirname(__DIR__, 2).'/.env.example');

        $this->assertSame('', $environment['MAIL_USERNAME'] ?? null);
        $this->assertSame('noreply@example.com', $environment['MAIL_FROM_ADDRESS'] ?? null);
    }

    /** @return array<string, string> */
    private function parseEnvironmentFile(string $path): array
    {
        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim(trim($value), "\"'");
        }

        return $values;
    }
}

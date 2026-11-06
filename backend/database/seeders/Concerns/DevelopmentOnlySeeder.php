<?php

namespace Database\Seeders\Concerns;

use LogicException;

trait DevelopmentOnlySeeder
{
    protected function ensureDevelopmentEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException(static::class.' is restricted to local and testing environments.');
        }
    }
}

<?php

namespace App\Services\Monitoring\Ai;

interface MonitoringAiProvider
{
    public function configured(): bool;

    public function name(): string;

    /** @param array<int, array{role:string,content:string}> $messages */
    public function generate(array $messages): string;
}

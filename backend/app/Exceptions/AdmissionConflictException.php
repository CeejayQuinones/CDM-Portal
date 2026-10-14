<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AdmissionConflictException extends HttpException
{
    public function __construct(
        public readonly string $conflictCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct(409, $message);
    }
}

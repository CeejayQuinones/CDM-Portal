<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;

class GoogleIdentityException extends AuthenticationException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

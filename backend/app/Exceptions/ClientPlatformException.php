<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ClientPlatformException extends HttpException
{
    public function __construct(int $statusCode, string $message)
    {
        parent::__construct($statusCode, $message);
    }
}

<?php

namespace App\Support;

use InvalidArgumentException;

class SanctumTokenExpiration
{
    public static function minutes(mixed $value): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('SANCTUM_TOKEN_EXPIRATION must be a positive integer number of minutes.');
        }

        if (is_string($value) && ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException('SANCTUM_TOKEN_EXPIRATION must be a positive integer number of minutes.');
        }

        $minutes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($minutes === false) {
            throw new InvalidArgumentException('SANCTUM_TOKEN_EXPIRATION must be a positive integer number of minutes.');
        }

        return $minutes;
    }
}

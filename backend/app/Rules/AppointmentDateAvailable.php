<?php

namespace App\Rules;

use App\Services\AppointmentAvailabilityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AppointmentDateAvailable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $message = app(AppointmentAvailabilityService::class)->unavailableMessage($value);

        if ($message !== null) {
            $fail($message);
        }
    }
}

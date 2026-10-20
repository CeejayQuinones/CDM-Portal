<?php

namespace App\Http\Requests;

use App\Rules\AppointmentDateAvailable;

class StoreAppointmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:today', new AppointmentDateAvailable],
            'appointment_time' => ['required', 'date_format:H:i'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ];
    }
}

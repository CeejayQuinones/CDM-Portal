<?php

namespace App\Http\Requests;

use App\Rules\AppointmentDateAvailable;

class UpdateAppointmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_date' => ['bail', 'sometimes', 'date', 'after_or_equal:today', new AppointmentDateAvailable],
            'appointment_time' => ['sometimes', 'date_format:H:i'],
            'status' => ['sometimes', 'in:pending,confirmed,completed,cancelled,no_show'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

class UpdateAppointmentRequest extends ApiFormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'appointment_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'appointment_time' => ['sometimes', 'date_format:H:i'],
            'status' => ['sometimes', 'in:pending,confirmed,completed,cancelled,no_show'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

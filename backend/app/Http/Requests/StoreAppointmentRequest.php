<?php

namespace App\Http\Requests;

class StoreAppointmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ];
    }
}

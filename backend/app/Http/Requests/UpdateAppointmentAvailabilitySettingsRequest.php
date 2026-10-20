<?php

namespace App\Http\Requests;

class UpdateAppointmentAvailabilitySettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'block_saturday' => ['required', 'boolean'],
            'block_sunday' => ['required', 'boolean'],
        ];
    }
}

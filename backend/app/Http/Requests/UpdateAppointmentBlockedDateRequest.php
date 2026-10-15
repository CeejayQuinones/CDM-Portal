<?php

namespace App\Http\Requests;

use App\Models\AppointmentBlockedDate;
use Illuminate\Validation\Rule;

class UpdateAppointmentBlockedDateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked_date' => ['sometimes', 'required', 'date'],
            'type' => ['sometimes', 'required', Rule::in(AppointmentBlockedDate::TYPES)],
            'reason' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

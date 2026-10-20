<?php

namespace App\Http\Requests;

use App\Models\AppointmentBlockedDate;
use Illuminate\Validation\Rule;

class StoreAppointmentBlockedDateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked_date' => ['required', 'date'],
            'type' => ['required', Rule::in(AppointmentBlockedDate::TYPES)],
            'reason' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

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
            'blocked_date' => ['sometimes', 'date'],
            'type' => ['sometimes', Rule::in(['holiday', 'maintenance', 'office_closure', 'school_event', 'other'])],
            'reason' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

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
            'type' => ['required', Rule::in(['holiday', 'maintenance', 'office_closure', 'school_event', 'other'])],
            'reason' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}

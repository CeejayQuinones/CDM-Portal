<?php

namespace App\Http\Requests;

class UpdateRegistrarRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject,complete,cancel'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'required_if:action,reject,cancel', 'string', 'max:2000'],
        ];
    }
}

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
            'action' => ['required', 'in:approve,reject,ready_for_release,return_to_processing,release,cancel'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'required_if:action,reject,cancel,return_to_processing', 'string', 'max:2000'],
        ];
    }
}

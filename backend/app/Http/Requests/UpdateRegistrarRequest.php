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
            'action' => ['required', 'in:approve,reject,process,ready_for_release,release,cancel'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

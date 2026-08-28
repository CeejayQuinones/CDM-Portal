<?php

namespace App\Http\Requests;

class UpdateStudentRecordLocationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cabinet_slot_id' => ['required', 'integer', 'exists:cabinet_slots,id'],
            'remarks' => ['nullable', 'string', 'max:3000'],
        ];
    }
}

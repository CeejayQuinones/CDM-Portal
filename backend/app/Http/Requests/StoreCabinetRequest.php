<?php

namespace App\Http\Requests;

class StoreCabinetRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cabinet_code')) {
            $this->merge([
                'cabinet_code' => strtoupper(trim((string) $this->input('cabinet_code'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'cabinet_code' => ['required', 'string', 'max:50', 'unique:cabinets,cabinet_code'],
            'description' => ['nullable', 'string', 'max:3000'],
            'rows' => ['required', 'integer', 'min:1', 'max:50'],
            'columns' => ['required', 'integer', 'min:1', 'max:50'],
            'slot_capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

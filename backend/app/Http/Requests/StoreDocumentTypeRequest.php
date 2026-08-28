<?php

namespace App\Http\Requests;

class StoreDocumentTypeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_name' => ['required', 'string', 'max:150', 'unique:document_types,document_name'],
            'description' => ['nullable', 'string', 'max:3000'],
            'requires_appointment' => ['required', 'boolean'],
            'processing_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'processing_days' => ['nullable', 'integer', 'min:1', 'max:255'],
        ];
    }
}

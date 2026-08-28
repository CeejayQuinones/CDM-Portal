<?php

namespace App\Http\Requests;

class StoreDocumentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ];
    }
}

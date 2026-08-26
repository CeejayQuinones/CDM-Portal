<?php

namespace App\Http\Requests;

use App\Models\DocumentType;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var DocumentType $documentType */
        $documentType = $this->route('documentType');

        return [
            'document_name' => ['sometimes', 'required', 'string', 'max:150', Rule::unique('document_types', 'document_name')->ignore($documentType)],
            'description' => ['nullable', 'string', 'max:3000'],
            'requires_appointment' => ['sometimes', 'boolean'],
            'processing_fee' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'processing_days' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}

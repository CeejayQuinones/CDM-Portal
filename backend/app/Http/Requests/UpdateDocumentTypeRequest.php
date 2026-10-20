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
            'workflow_category' => ['sometimes', Rule::in(['supporting_record', 'official_issuance'])],
            'requires_appointment' => ['sometimes', 'boolean'],
            'student_requestable' => ['sometimes', 'boolean'],
            'student_uploadable' => ['sometimes', 'boolean', Rule::prohibitedIf(fn (): bool => $this->input('workflow_category', $documentType->workflow_category) !== 'supporting_record' && $this->boolean('student_uploadable'))],
            'processing_fee' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'processing_days' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}

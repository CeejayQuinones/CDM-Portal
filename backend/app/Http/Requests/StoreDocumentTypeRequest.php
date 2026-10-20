<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

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
            'workflow_category' => ['required', Rule::in(['supporting_record', 'official_issuance'])],
            'requires_appointment' => ['required', 'boolean'],
            'student_requestable' => ['required', 'boolean'],
            'student_uploadable' => ['required', 'boolean', Rule::prohibitedIf(fn (): bool => $this->input('workflow_category') !== 'supporting_record' && $this->boolean('student_uploadable'))],
            'processing_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'processing_days' => ['nullable', 'integer', 'min:1', 'max:255'],
        ];
    }
}

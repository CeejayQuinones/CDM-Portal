<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreDocumentRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $category = trim((string) $this->input('purpose_category'));
        $details = $category === 'Others' ? trim((string) $this->input('purpose_details')) : null;

        $this->merge([
            'purpose_category' => $category,
            'purpose_details' => $details,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'purpose_category' => ['bail', 'required', 'string', Rule::in(['Scholarship', 'Evaluation', 'Others'])],
            'purpose_details' => ['bail', 'nullable', 'required_if:purpose_category,Others', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'purpose_category.required' => 'Select a reason or purpose for this request.',
            'purpose_category.in' => 'Select a valid reason or purpose for this request.',
            'purpose_details.required_if' => 'Please specify your purpose when Others is selected.',
            'purpose_details.max' => 'The purpose explanation must not exceed 500 characters.',
        ];
    }
}

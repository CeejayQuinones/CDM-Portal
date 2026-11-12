<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreDocumentRequestBatch extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('items'))) {
            return;
        }

        $items = array_map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (is_string($item['purpose_category'] ?? null)) {
                $item['purpose_category'] = trim($item['purpose_category']);
            }
            $item['purpose_details'] = ($item['purpose_category'] ?? null) === 'Others'
                ? (is_string($item['purpose_details'] ?? null) ? trim($item['purpose_details']) : $item['purpose_details'] ?? null)
                : null;

            return $item;
        }, $this->input('items'));

        $this->merge(['items' => $items]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*' => ['required', 'array:document_type_id,quantity,purpose_category,purpose_details'],
            'items.*.document_type_id' => ['required', 'integer', 'distinct', 'exists:document_types,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'items.*.purpose_category' => ['bail', 'required', 'string', Rule::in(['Scholarship', 'Evaluation', 'Others'])],
            'items.*.purpose_details' => ['nullable', 'required_if:items.*.purpose_category,Others', 'string', 'max:500'],
        ];
    }
}

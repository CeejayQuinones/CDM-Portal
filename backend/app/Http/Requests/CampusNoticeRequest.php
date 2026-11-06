<?php

namespace App\Http\Requests;

use App\Models\CampusNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CampusNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:120', 'not_regex:/[<>]/'],
            'message' => ['required', 'string', 'min:3', 'max:1000', 'not_regex:/[<>]/'],
            'category' => ['required', Rule::in(CampusNotice::CATEGORIES)],
            'priority' => ['required', Rule::in(CampusNotice::PRIORITIES)],
            'audiences' => ['required', 'array', 'min:1', 'max:2'],
            'audiences.*' => ['required', 'distinct', Rule::in(CampusNotice::AUDIENCES)],
            'publication_start_at' => ['nullable', 'date'],
            'publication_end_at' => ['nullable', 'date', 'after:publication_start_at'],
            'effective_start_at' => ['nullable', 'date'],
            'effective_end_at' => ['nullable', 'date', 'after:effective_start_at'],
            'is_recurring' => ['required', 'boolean'],
            'recurrence_weekdays' => ['nullable', 'array', 'max:7', 'required_if:is_recurring,true'],
            'recurrence_weekdays.*' => ['required', 'distinct', Rule::in(CampusNotice::WEEKDAYS)],
            'requires_acknowledgement' => ['required', 'boolean'],
            'official_source' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/'],
            'affected_scope' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/'],
            'target_route' => ['nullable', 'string', 'max:255', 'regex:/^\/(admission|enrollment|document-requests|settings)(\/[a-z0-9-]+)*$/'],
            'status' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'published_by_user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('category') === 'class_suspension') {
                foreach (['official_source', 'affected_scope', 'effective_start_at', 'effective_end_at'] as $field) {
                    if (! filled($this->input($field))) {
                        $validator->errors()->add($field, 'Official class-suspension notices require a verified source, affected scope, and effective period.');
                    }
                }
            }

            if (! $this->boolean('is_recurring') && filled($this->input('recurrence_weekdays'))) {
                $validator->errors()->add('recurrence_weekdays', 'Weekdays are only valid for recurring reminders.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'message' => trim((string) $this->input('message')),
            'official_source' => $this->filled('official_source') ? trim((string) $this->input('official_source')) : null,
            'affected_scope' => $this->filled('affected_scope') ? trim((string) $this->input('affected_scope')) : null,
            'target_route' => $this->filled('target_route') ? trim((string) $this->input('target_route')) : null,
        ]);
    }
}

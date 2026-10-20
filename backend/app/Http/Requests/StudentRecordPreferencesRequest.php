<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StudentRecordPreferencesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visible_columns' => [
                'required',
                'array',
                'min:2',
                'max:7',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || ! in_array('student_number', $value, true) || ! in_array('name', $value, true)) {
                        $fail('Student number and name must remain visible.');
                    }
                },
            ],
            'visible_columns.*' => ['distinct', Rule::in(['student_number', 'name', 'program', 'year_level', 'section', 'student_status', 'physical_record_status'])],
        ];
    }
}

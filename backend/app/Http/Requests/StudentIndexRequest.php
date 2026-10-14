<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StudentIndexRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'course' => ['nullable', 'string', 'max:50'],
            'year_level' => ['nullable', 'integer', 'between:1,20'],
            'section' => ['nullable', 'string', 'max:100'],
            'student_status' => ['nullable', 'in:regular,irregular,graduated,transferred,dropped,leave_of_absence'],
            'attention' => ['nullable', Rule::in(['any', 'profile', 'physical_record', 'documents'])],
            'physical_record_status' => ['nullable', Rule::in(['available', 'unavailable'])],
            'sort' => ['nullable', Rule::in(['student_number', 'name', 'program', 'year_level', 'student_status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

<?php

namespace App\Http\Requests;

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
            'student_status' => ['nullable', 'in:regular,irregular,graduated,transferred,dropped,leave_of_absence'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

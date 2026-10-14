<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StudentRecordSavedFilterRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $savedFilter = $this->route('savedFilter');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('student_record_saved_filters', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($savedFilter?->id),
            ],
            'filters' => ['required', 'array:search,course,year_level,section,student_status,attention,physical_record_status,sort,direction'],
            'filters.search' => ['nullable', 'string', 'max:100'],
            'filters.course' => ['nullable', 'string', 'max:50'],
            'filters.year_level' => ['nullable', 'integer', 'between:1,20'],
            'filters.section' => ['nullable', 'string', 'max:100'],
            'filters.student_status' => ['nullable', Rule::in(['regular', 'irregular', 'graduated', 'transferred', 'dropped', 'leave_of_absence'])],
            'filters.attention' => ['nullable', Rule::in(['any', 'profile', 'physical_record', 'documents'])],
            'filters.physical_record_status' => ['nullable', Rule::in(['available', 'unavailable'])],
            'filters.sort' => ['nullable', Rule::in(['student_number', 'name', 'program', 'year_level', 'student_status'])],
            'filters.direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}

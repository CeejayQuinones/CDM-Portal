<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRecordReportRequest extends FormRequest
{
    public const TYPES = ['master-list', 'academic-summary', 'physical-records', 'activity-summary'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['report_type' => $this->route('reportType')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report_type' => ['required', Rule::in(self::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:30'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'year_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'availability' => ['nullable', Rule::in(['available', 'unavailable'])],
            'cabinet_id' => ['nullable', 'integer', 'exists:cabinets,id'],
            'action_type' => ['nullable', 'string', 'max:80'],
            'staff_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}

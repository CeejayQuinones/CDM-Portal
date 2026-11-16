<?php

namespace App\Http\Requests;

class UpdateStudentAcademicRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', 'integer', 'between:1,8'],
            'admission_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}

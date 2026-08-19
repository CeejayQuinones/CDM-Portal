<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Student */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'record_created_at' => $this->created_at?->toISOString(),
            'record_updated_at' => $this->updated_at?->toISOString(),
            'created_by' => null,
            'student_number' => $this->student_number,
            'year_level' => $this->year_level,
            'student_status' => $this->student_status,
            'admission_date' => $this->admission_date?->toDateString(),
            'full_name' => $this->whenLoaded('userProfile', fn () => collect([
                $this->userProfile->first_name,
                $this->userProfile->middle_name,
                $this->userProfile->last_name,
                $this->userProfile->suffix,
            ])->filter()->join(' ')),
            'account_status' => $this->whenLoaded('user', fn () => $this->user->status),
            'profile' => $this->whenLoaded('userProfile', fn () => [
                'first_name' => $this->userProfile->first_name,
                'middle_name' => $this->userProfile->middle_name,
                'last_name' => $this->userProfile->last_name,
                'suffix' => $this->userProfile->suffix,
                'birth_date' => $this->userProfile->birth_date?->toDateString(),
                'gender' => $this->userProfile->gender,
                'civil_status' => $this->userProfile->civil_status,
                'nationality' => $this->userProfile->nationality,
                'email' => $this->userProfile->email,
                'contact_number' => $this->userProfile->contact_number,
                'address' => $this->userProfile->address,
                'profile_photo' => $this->userProfile->profile_photo,
            ]),
            'account' => $this->whenLoaded('user', fn () => [
                'username' => $this->user->username,
                'status' => $this->user->status,
                'last_login' => $this->user->last_login?->toISOString(),
                'is_first_login' => $this->user->is_first_login,
                'role' => $this->user->relationLoaded('role') ? $this->user->role?->role_name : null,
                'linked' => true,
            ]),
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'code' => $this->course->course_code,
                'name' => $this->course->course_name,
            ]),
            'curriculum' => $this->whenLoaded('curriculum', fn () => [
                'id' => $this->curriculum->id,
                'code' => $this->curriculum->curriculum_code,
                'name' => $this->curriculum->curriculum_name,
                'effective_year' => $this->curriculum->effective_year,
            ]),
            'department' => $this->whenLoaded('course', fn () => $this->course->relationLoaded('department') && $this->course->department ? [
                'id' => $this->course->department->id,
                'code' => $this->course->department->department_code,
                'name' => $this->course->department->department_name,
            ] : null),
            'academic_year' => $this->whenLoaded('latestEnrollment', fn () => $this->latestEnrollment?->relationLoaded('academicYear') && $this->latestEnrollment->academicYear ? [
                'id' => $this->latestEnrollment->academicYear->id,
                'school_year' => $this->latestEnrollment->academicYear->school_year,
            ] : null),
            'semester' => $this->whenLoaded('latestEnrollment', fn () => $this->latestEnrollment?->relationLoaded('semester') && $this->latestEnrollment->semester ? [
                'id' => $this->latestEnrollment->semester->id,
                'name' => $this->latestEnrollment->semester->semester_name,
            ] : null),
            'enrollment_status' => $this->whenLoaded('latestEnrollment', fn () => $this->latestEnrollment?->status),
        ];
    }
}

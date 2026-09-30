<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrollment = $this->relationLoaded('currentEnrollment') ? $this->currentEnrollment : null;
        $finalApplication = $enrollment?->relationLoaded('application') ? $enrollment->application : null;
        $assignedApplication = $this->relationLoaded('currentEnrollmentApplication') ? $this->currentEnrollmentApplication : null;
        $rank = function ($year, $semester, $period): array {
            $open = $period?->enabled && $period->opens_at?->lte(now()) && $period->closes_at?->gte(now());
            $active = $year?->status === 'active' && $semester?->status === 'active';

            return [$open ? 2 : ($active ? 1 : 0), (string) ($year?->start_date ?? ''), (int) ($semester?->semester_order ?? 0)];
        };
        $enrollmentRank = $enrollment ? $rank($enrollment->academicYear, $enrollment->semester, $finalApplication?->period) : [-1, '', 0];
        $applicationRank = $assignedApplication ? $rank($assignedApplication->academicYear, $assignedApplication->semester, $assignedApplication->period) : [-1, '', 0];
        $useAssignedApplication = $assignedApplication && (! $enrollment || $applicationRank > $enrollmentRank);
        $application = $useAssignedApplication ? $assignedApplication : $finalApplication;
        $year = $useAssignedApplication ? $assignedApplication->academicYear : $enrollment?->academicYear;
        $semester = $useAssignedApplication ? $assignedApplication->semester : $enrollment?->semester;
        $section = $useAssignedApplication ? $assignedApplication->section : $enrollment?->section;
        $selectedRank = $useAssignedApplication ? $applicationRank : $enrollmentRank;
        $isCurrent = $selectedRank[0] > 0;
        $summary = ($enrollment || $assignedApplication) ? [
            'id' => $useAssignedApplication ? null : $enrollment?->id,
            'application_id' => $application?->id,
            'assignment_state' => $useAssignedApplication ? 'assigned_pending_finalization' : 'official',
            'record_scope' => $isCurrent ? 'current' : 'latest',
            'academic_year' => $year?->school_year,
            'semester' => $semester?->semester_name,
            'section' => $section?->section_name,
            'year_level' => $application?->year_level ?? $this->year_level,
            'status' => $useAssignedApplication ? $application?->status : $enrollment?->status,
            'classification' => $application?->classification,
            'course' => $application?->course ? [
                'id' => $application->course->id,
                'code' => $application->course->course_code,
                'name' => $application->course->course_name,
            ] : null,
            'curriculum' => $application?->curriculum ? [
                'id' => $application->curriculum->id,
                'code' => $application->curriculum->curriculum_code,
                'name' => $application->curriculum->curriculum_name,
            ] : null,
        ] : null;

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
            'academic_year' => $this->when(array_key_exists('current_enrollment_id', $this->resource->getAttributes()), fn () => $year ? [
                'id' => $year->id,
                'school_year' => $year->school_year,
            ] : null),
            'semester' => $this->when(array_key_exists('current_enrollment_id', $this->resource->getAttributes()), fn () => $semester ? [
                'id' => $semester->id,
                'name' => $semester->semester_name,
            ] : null),
            'enrollment_status' => $this->when(array_key_exists('current_enrollment_id', $this->resource->getAttributes()), fn () => $summary['status'] ?? null),
            'current_section' => $this->when(array_key_exists('current_enrollment_id', $this->resource->getAttributes()), fn () => $section?->section_name),
            'current_enrollment' => $this->when(array_key_exists('current_enrollment_id', $this->resource->getAttributes()), fn () => $summary),
            'admission_record' => $this->when(array_key_exists('has_admission_record', $this->resource->getAttributes()),
                fn () => $this->has_admission_record ? 'Available' : 'No linked Admission record'),
            'enrollment_application_available' => $this->when(isset($this->has_enrollment_application), fn () => (bool) $this->has_enrollment_application),
            'documents' => StudentDocumentResource::collection($this->whenLoaded('documents')),
            'physical_record_location' => $this->whenLoaded(
                'physicalRecordLocation',
                fn () => $this->physicalRecordLocation
                    ? new StudentRecordLocationResource($this->physicalRecordLocation)
                    : null,
            ),
            'recent_document_requests' => $this->whenLoaded('documentRequests', fn () => $this->documentRequests->map(fn ($documentRequest) => [
                'id' => $documentRequest->id,
                'document' => $documentRequest->relationLoaded('documentType') ? $documentRequest->documentType?->document_name : null,
                'status' => $documentRequest->status,
                'request_date' => $documentRequest->request_date?->toDateString(),
                'release_date' => $documentRequest->release_date?->toDateString(),
                'remarks' => $documentRequest->remarks,
            ])),
        ];
    }
}

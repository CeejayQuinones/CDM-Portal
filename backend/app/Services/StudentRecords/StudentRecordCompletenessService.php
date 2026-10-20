<?php

namespace App\Services\StudentRecords;

use App\Models\Student;
use App\Models\StudentRecordAuditEvent;

class StudentRecordCompletenessService
{
    /** @return array{status:string,label:string,issues:list<array{key:string,label:string,area:string}>} */
    public function evaluate(Student $student): array
    {
        $student->loadMissing(['userProfile', 'course', 'documents', 'physicalRecordLocation']);
        $profile = $student->userProfile;
        $issues = [];

        foreach ([
            'student_number' => [$student->student_number, 'Student number is missing.', 'identity'],
            'course' => [$student->course_id, 'Program identity is missing.', 'identity'],
            'first_name' => [$profile?->first_name, 'First name is missing.', 'profile'],
            'last_name' => [$profile?->last_name, 'Last name is missing.', 'profile'],
        ] as $key => [$value, $label, $area]) {
            if (blank($value)) {
                $issues[] = compact('key', 'label', 'area');
            }
        }

        foreach ([
            'birth_date' => [$profile?->birth_date, 'Birth date needs completion.'],
            'email' => [$profile?->email, 'Email needs completion.'],
            'contact_number' => [$profile?->contact_number, 'Contact number needs completion.'],
            'address' => [$profile?->address, 'Address needs completion.'],
        ] as $key => [$value, $label]) {
            if (blank($value)) {
                $issues[] = ['key' => $key, 'label' => $label, 'area' => 'profile'];
            }
        }

        if (! $student->physicalRecordLocation) {
            $issues[] = ['key' => 'physical_record_location', 'label' => 'Physical record location is not assigned.', 'area' => 'physical_record'];
        }
        if ($student->documents->isEmpty()) {
            $issues[] = ['key' => 'document_metadata', 'label' => 'No document metadata is recorded.', 'area' => 'documents'];
        } elseif ($student->documents->contains(fn ($document) => $document->availability_status === 'missing'
            || in_array($document->verification_status, ['pending', 'rejected'], true)
            || $document->physical_copy_status === 'required')) {
            $issues[] = ['key' => 'document_attention', 'label' => 'One or more documents need staff attention.', 'area' => 'documents'];
        }

        $hasIdentityIssue = collect($issues)->contains('area', 'identity');
        $status = $hasIdentityIssue ? 'incomplete' : ($issues === [] ? 'complete' : 'needs_attention');

        return [
            'status' => $status,
            'label' => match ($status) {
                'complete' => 'Complete',
                'incomplete' => 'Incomplete',
                default => 'Needs Attention',
            },
            'issues' => $issues,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function duplicateWarnings(Student $student): array
    {
        $profile = $student->userProfile;
        if (! $profile?->birth_date || blank($profile->first_name) || blank($profile->last_name)) {
            return [];
        }

        return Student::query()
            ->whereKeyNot($student->id)
            ->whereHas('userProfile', fn ($query) => $query
                ->whereDate('birth_date', $profile->birth_date)
                ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($profile->first_name)])
                ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($profile->last_name)]))
            ->with('userProfile:id,first_name,last_name')
            ->limit(5)
            ->get(['id', 'user_profile_id', 'student_number'])
            ->map(fn (Student $match): array => [
                'type' => 'probable_profile_duplicate',
                'student_id' => $match->id,
                'student_number' => $match->student_number,
                'student_name' => trim($match->userProfile->first_name.' '.$match->userProfile->last_name),
                'message' => 'Matching name and birth date require staff review.',
            ])->all();
    }

    /** @return array<string, mixed> */
    public function physicalLocator(Student $student): array
    {
        $student->loadMissing('physicalRecordLocation.cabinetSlot.cabinet');
        $event = StudentRecordAuditEvent::query()
            ->where('student_id', $student->id)
            ->where('action', 'physical_record_location_changed')
            ->with(['actor.profile:id,user_id,first_name,last_name'])
            ->latest('created_at')->latest('id')->first();

        $location = $student->physicalRecordLocation;

        return [
            'availability' => $location ? 'available' : 'unavailable',
            'cabinet' => $location?->cabinetSlot?->cabinet?->cabinet_code,
            'slot' => $location?->cabinetSlot?->slot_code,
            'assigned_at' => $location?->assigned_at?->toISOString(),
            'last_moved_at' => $event?->created_at?->toISOString(),
            'last_moved_by' => $event ? (trim(($event->actor?->profile?->first_name ?? '').' '.($event->actor?->profile?->last_name ?? '')) ?: $event->actor?->username) : null,
        ];
    }
}

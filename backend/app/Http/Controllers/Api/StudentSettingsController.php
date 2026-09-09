<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->student($request))]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $data = $request->validate(['preferred_display_name' => ['nullable', 'string', 'max:120'], 'bio' => ['nullable', 'string', 'max:1000']]);
        $student->settings()->updateOrCreate([], $data);

        return response()->json(['success' => true, 'data' => $this->payload($student->fresh())]);
    }

    public function updateContact(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255'], 'contact_number' => ['nullable', 'string', 'max:20'], 'address' => ['nullable', 'string', 'max:1000']]);
        $student->userProfile->update($data);

        return response()->json(['success' => true, 'data' => $this->payload($student->fresh())]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $data = $request->validate(['notification_preferences' => ['nullable', 'array'], 'academic_preferences' => ['nullable', 'array'], 'appearance' => ['nullable', 'in:light,dark,system']]);
        $student->settings()->updateOrCreate([], $data);

        return response()->json(['success' => true, 'data' => $this->payload($student->fresh())]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $profile = $student->userProfile;
        if ($profile->profile_photo) {
            Storage::disk('public')->delete($profile->profile_photo);
        }
        $profile->update(['profile_photo' => $request->file('avatar')->store('student-avatars', 'public')]);

        return response()->json(['success' => true, 'data' => $this->payload($student->fresh())]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $profile = $this->student($request)->userProfile;
        if ($profile->profile_photo) {
            Storage::disk('public')->delete($profile->profile_photo);
        }
        $profile->update(['profile_photo' => null]);

        return response()->json(['success' => true]);
    }

    private function student(Request $request): Student
    {
        return Student::query()->with(['user.role', 'userProfile', 'course', 'latestEnrollment.section', 'latestEnrollment.academicYear', 'latestEnrollment.semester', 'settings', 'documents.documentType', 'documentRequests.activeAppointment'])
            ->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function payload(Student $student): array
    {
        $documents = $student->documents;
        $total = DocumentType::query()->where('status', 'active')->count();
        $complete = $documents->where('verification_status', 'verified')->count();
        $profile = $student->userProfile;
        $requests = $student->documentRequests;
        $appearance = $student->settings?->appearance ?? 'system';

        return [
            'profile' => ['preferred_display_name' => $student->settings?->preferred_display_name, 'bio' => $student->settings?->bio, 'avatar_url' => $profile?->profile_photo ? Storage::disk('public')->url($profile->profile_photo) : null],
            'account' => ['school_email' => $student->user?->username, 'role' => $student->user?->role?->role_name, 'status' => $student->user?->status, 'last_login' => $student->user?->last_login?->toIso8601String(), 'created_at' => $student->user?->created_at?->toIso8601String(), 'profile_updated_at' => $profile?->updated_at?->toIso8601String()],
            'student_status' => ['enrollment_status' => $student->student_status, 'academic_year' => $student->latestEnrollment?->academicYear?->year_name, 'semester' => $student->latestEnrollment?->semester?->semester_name, 'year_level' => $student->year_level, 'section' => $student->latestEnrollment?->section?->section_name, 'program' => $student->course?->course_name],
            'official' => ['legal_name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->middle_name, $profile?->last_name]))), 'student_number' => $student->student_number, 'program' => $student->course?->course_name, 'year_level' => $student->year_level, 'section' => $student->latestEnrollment?->section?->section_name],
            'contact' => ['personal_email' => $profile?->email, 'mobile' => $profile?->contact_number, 'address' => $profile?->address],
            'document_status' => ['complete' => $complete, 'total' => $total, 'items' => $documents->map(fn ($document) => ['name' => $document->documentType?->document_name, 'status' => $document->verification_status])->values(), 'active_requests' => $requests->whereIn('status', ['pending', 'processing', 'approved'])->count(), 'completed_requests' => $requests->where('status', 'completed')->count(), 'upcoming_appointment' => $requests->pluck('activeAppointment')->filter()->first()?->appointment_date?->toDateString()],
            'preferences' => ['notification_preferences' => $student->settings?->notification_preferences ?? [], 'academic_preferences' => $student->settings?->academic_preferences ?? []],
            'appearance' => $appearance,
        ];
    }
}

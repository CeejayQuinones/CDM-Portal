<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RegistrarSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response($this->user($request));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
        ]);
        $user = $this->user($request);
        $user->profile()->firstOrFail()->update($data);

        return $this->response($user->fresh());
    }

    public function updateContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $this->user($request);
        $user->profile()->firstOrFail()->update($data);

        return $this->response($user->fresh());
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $user = $this->user($request);
        $profile = $user->profile()->firstOrFail();

        if ($profile->profile_photo) {
            Storage::disk('public')->delete($profile->profile_photo);
        }

        $profile->update(['profile_photo' => $request->file('avatar')->store('staff-avatars', 'public')]);

        return $this->response($user->fresh());
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $profile = $this->user($request)->profile()->firstOrFail();

        if ($profile->profile_photo) {
            Storage::disk('public')->delete($profile->profile_photo);
        }

        $profile->update(['profile_photo' => null]);

        return response()->json(['success' => true]);
    }

    private function user(Request $request): User
    {
        return $request->user()->loadMissing(['role', 'profile', 'registrarStaff']);
    }

    private function response(User $user): JsonResponse
    {
        $user->load(['role', 'profile', 'registrarStaff']);
        $profile = $user->profile;
        $staff = $user->registrarStaff;

        return response()->json([
            'success' => true,
            'data' => [
                'profile' => [
                    'first_name' => $profile?->first_name,
                    'middle_name' => $profile?->middle_name,
                    'last_name' => $profile?->last_name,
                    'suffix' => $profile?->suffix,
                    'avatar_url' => $profile?->profile_photo_url,
                ],
                'contact' => [
                    'personal_email' => $profile?->email,
                    'mobile' => $profile?->contact_number,
                    'address' => $profile?->address,
                ],
                'account' => [
                    'username' => $user->username,
                    'role' => $user->role?->role_name,
                    'status' => $user->status,
                    'last_login' => $user->last_login?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'profile_updated_at' => $profile?->updated_at?->toIso8601String(),
                ],
                'official' => [
                    'employee_number' => $staff?->employee_number,
                    'position' => $staff?->position,
                    'employment_status' => $staff?->employment_status,
                    'staff_status' => $staff?->status,
                ],
            ],
        ]);
    }
}

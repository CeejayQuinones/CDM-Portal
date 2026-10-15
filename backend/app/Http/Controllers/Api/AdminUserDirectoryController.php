<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserDirectoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = User::query()
            ->with([
                'role',
                'profile',
                'student.course.department',
                'professor.department',
                'registrarStaff',
            ])
            ->orderBy('username');

        if (! empty($validated['role'])) {
            $query->whereHas('role', fn ($q) => $q->where('role_name', $validated['role']));
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhereHas('profile', function ($profile) use ($search): void {
                        $profile->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('student', fn ($s) => $s->where('student_number', 'like', "%{$search}%"))
                    ->orWhereHas('professor', fn ($p) => $p->where('employee_number', 'like', "%{$search}%"))
                    ->orWhereHas('registrarStaff', fn ($r) => $r->where('employee_number', 'like', "%{$search}%"));
            });
        }

        $users = $query->get()->map(function (User $user) {
            $profile = $user->profile;
            $fullName = trim(collect([$profile?->first_name, $profile?->middle_name, $profile?->last_name])->filter()->implode(' '));

            return [
                'id' => $user->id,
                'username' => $user->username,
                'status' => $user->status,
                'is_first_login' => (bool) $user->is_first_login,
                'last_login' => $user->last_login?->toIso8601String(),
                'role' => [
                    'id' => $user->role?->id,
                    'role_name' => $user->role?->role_name,
                ],
                'name' => $fullName !== '' ? $fullName : $user->username,
                'email' => $profile?->email,
                'contact_number' => $profile?->contact_number,
                'student_number' => $user->student?->student_number,
                'year_level' => $user->student?->year_level,
                'employee_number' => $user->professor?->employee_number ?? $user->registrarStaff?->employee_number,
                'department' => $user->professor?->department?->department_name
                    ?? $user->student?->course?->department?->department_name,
                'course_code' => $user->student?->course?->course_code,
            ];
        });

        $summary = [
            'total' => $users->count(),
            'by_role' => $users->groupBy(fn ($u) => $u['role']['role_name'] ?? 'Unknown')
                ->map->count()
                ->all(),
            'active' => $users->where('status', 'active')->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'User directory retrieved successfully.',
            'data' => [
                'summary' => $summary,
                'users' => $users->values(),
            ],
        ]);
    }
}

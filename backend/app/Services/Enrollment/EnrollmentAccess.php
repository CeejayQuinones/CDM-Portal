<?php

namespace App\Services\Enrollment;

use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionHandoffService;

class EnrollmentAccess
{
    public static function require(User $user, bool $staff = false, bool $lock = false): User
    {
        $q = User::with('role')->whereKey($user->id);
        if ($lock) {
            $q->lockForUpdate();
        } $actor = $q->first();
        $allowed = $actor && $actor->status === 'active' && ($staff
            ? in_array($actor->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true)
            : ($actor->role?->role_name === Role::STUDENT
                || ($actor->role?->role_name === Role::GUEST && app(AdmissionHandoffService::class)->isAdmittedGuest($actor, $lock))));
        abort_unless($allowed, 403, 'Enrollment access denied.');

        return $actor;
    }
}

<?php

namespace App\Services\Enrollment;

use App\Models\Role;
use App\Models\User;

class EnrollmentAccess
{
    public static function require(User $user, bool $staff = false, bool $lock = false): User
    {
        $q = User::with('role')->whereKey($user->id);
        if ($lock) {
            $q->lockForUpdate();
        } $actor = $q->first();
        abort_unless($actor && $actor->status === 'active' && in_array($actor->role?->role_name, $staff ? [Role::ADMIN, Role::REGISTRAR_STAFF] : [Role::STUDENT], true), 403, 'Enrollment access denied.');

        return $actor;
    }
}

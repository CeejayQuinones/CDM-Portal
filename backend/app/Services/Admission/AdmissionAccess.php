<?php

namespace App\Services\Admission;

use App\Models\Role;
use App\Models\User;

class AdmissionAccess
{
    public static function require(User $user, string $capability): User
    {
        $actor = $user->fresh('role');
        $roles = match ($capability) {
            'configure' => [Role::ADMIN],
            'review' => [Role::REGISTRAR_STAFF],
            'read' => [Role::GUEST, Role::STUDENT],
            'write' => [Role::GUEST],
        };
        abort_unless($actor && $actor->status === 'active' && in_array($actor->role?->role_name, $roles, true), 403, 'Admission access denied.');
        if ($capability === 'write') {
            abort_if($actor->student()->exists(), 403, 'Admission access is read only.');
        }

        return $actor;
    }
}

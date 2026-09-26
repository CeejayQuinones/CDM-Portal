<?php

namespace App\Policies\Admission;

use App\Models\User;
use App\Services\Admission\AdmissionAccess;

class AdmissionCyclePolicy
{
    public function configure(User $user): bool
    {
        return $user->status === 'active' && in_array($user->role?->role_name, AdmissionAccess::STAFF_ROLES, true);
    }
}

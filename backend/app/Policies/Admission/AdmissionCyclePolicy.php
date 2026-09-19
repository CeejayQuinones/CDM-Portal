<?php

namespace App\Policies\Admission;

use App\Models\Role;
use App\Models\User;

class AdmissionCyclePolicy
{
    public function configure(User $user): bool
    {
        return $user->status === 'active' && $user->role?->role_name === Role::ADMIN;
    }
}

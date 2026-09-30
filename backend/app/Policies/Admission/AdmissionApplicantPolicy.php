<?php

namespace App\Policies\Admission;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Role;
use App\Models\User;
use App\Services\Admission\AdmissionAccess;

class AdmissionApplicantPolicy
{
    public function create(User $user): bool
    {
        return $user->status === 'active' && $user->role?->role_name === Role::GUEST
            && ! $user->student()->exists();
    }

    public function viewOwn(User $user): bool
    {
        return $user->status === 'active' && in_array($user->role?->role_name, [Role::GUEST, Role::STUDENT], true);
    }

    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && in_array($user->role?->role_name, AdmissionAccess::STAFF_ROLES, true);
    }

    public function view(User $user, AdmissionApplicant $applicant): bool
    {
        return $this->viewAny($user) || ($this->viewOwn($user) && $user->id === $applicant->user_id);
    }
}

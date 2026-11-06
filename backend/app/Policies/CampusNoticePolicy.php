<?php

namespace App\Policies;

use App\Models\CampusNotice;
use App\Models\Role;
use App\Models\User;

class CampusNoticePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, CampusNotice $notice): bool
    {
        return $notice->status === 'draft' && $this->canManageCategory($user, $notice->category);
    }

    public function publish(User $user, CampusNotice $notice): bool
    {
        return $notice->status === 'draft' && $this->canManageCategory($user, $notice->category);
    }

    public function archive(User $user, CampusNotice $notice): bool
    {
        return $notice->status !== 'archived' && $this->canManageCategory($user, $notice->category);
    }

    public function canManageCategory(User $user, string $category): bool
    {
        $role = $user->loadMissing('role')->role?->role_name;
        if ($role === Role::ADMIN) {
            return true;
        }

        return $role === Role::REGISTRAR_STAFF && in_array($category, [
            'general', 'enrollment', 'admission', 'registrar_reminder', 'academic_reminder',
        ], true);
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->loadMissing('role')->role?->role_name, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
    }
}

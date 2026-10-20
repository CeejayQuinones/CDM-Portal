<?php

namespace App\Http\Middleware;

use App\Models\Role;

class EnsureRegistrarStaffRole extends EnsureRole
{
    protected function requiredRole(): string
    {
        return Role::REGISTRAR_STAFF;
    }
}

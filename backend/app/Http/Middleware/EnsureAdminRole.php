<?php

namespace App\Http\Middleware;

use App\Models\Role;

class EnsureAdminRole extends EnsureRole
{
    protected function requiredRole(): string
    {
        return Role::ADMIN;
    }
}

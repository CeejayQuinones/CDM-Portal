<?php

namespace App\Http\Middleware;

use App\Models\Role;

class EnsureProfessorRole extends EnsureRole
{
    protected function requiredRole(): string
    {
        return Role::PROFESSOR;
    }
}

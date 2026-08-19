<?php

namespace App\Http\Middleware;

use App\Models\Role;

class EnsureStudentRole extends EnsureRole
{
    protected function requiredRole(): string
    {
        return Role::STUDENT;
    }
}

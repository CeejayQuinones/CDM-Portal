<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonitoringAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->role?->role_name;
        abort_unless(in_array($role, [Role::STUDENT, Role::PROFESSOR, Role::REGISTRAR_STAFF], true), 403);

        return $next($request);
    }
}

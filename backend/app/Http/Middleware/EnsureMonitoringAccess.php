<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Support\ClientPlatform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMonitoringAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $role = $user?->role?->role_name;
        abort_unless($user?->status === 'active', 403, 'Active account access required.');
        abort_unless(in_array($role, [Role::STUDENT, Role::PROFESSOR, Role::REGISTRAR_STAFF, Role::ADMIN], true), 403);
        $client = $request->header(ClientPlatform::HEADER);
        if ($role === Role::PROFESSOR && filled($client)) {
            abort_unless($client === ClientPlatform::WEB, 403, 'Professor Monitoring is available through the Web client.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEventPlatformAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $purpose = 'workspace'): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing('role');

        $client = trim((string) $request->header(ClientPlatform::HEADER));
        $role = $user->role?->role_name;
        $semiCoordinator = $role === Role::PROFESSOR
            && $user->eventRoleAssignments()->granting()->where('responsibility', 'semi_coordinator')->exists();
        $desktopAdministrator = $client === ClientPlatform::DESKTOP && ($role === Role::ADMIN || $semiCoordinator);
        $mobileParticipant = $client === ClientPlatform::MOBILE
            && in_array($role, [Role::PROFESSOR, Role::STUDENT], true)
            && ! $semiCoordinator;

        $allowed = match ($purpose) {
            'promotion' => $client === ClientPlatform::WEB,
            'desktop' => $desktopAdministrator,
            'mobile' => $mobileParticipant,
            default => $desktopAdministrator || $mobileParticipant,
        };

        abort_unless($allowed, 403, 'This account cannot access the Event module from the selected client.');

        return $next($request);
    }
}

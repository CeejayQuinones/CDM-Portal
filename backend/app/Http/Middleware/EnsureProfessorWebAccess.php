<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Support\ClientPlatform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfessorWebAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('role');

        if ($user?->role?->role_name === Role::PROFESSOR
            && ! in_array($request->header(ClientPlatform::HEADER), [ClientPlatform::WEB, ClientPlatform::MOBILE], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Professor academic work is available through the Website or Android application.',
            ], 403);
        }

        return $next($request);
    }
}

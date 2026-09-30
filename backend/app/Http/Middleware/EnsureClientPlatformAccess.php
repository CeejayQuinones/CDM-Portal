<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ClientPlatformAccessService;
use App\Support\ClientPlatform;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientPlatformAccess
{
    public function __construct(private readonly ClientPlatformAccessService $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->denied('Unauthenticated.', 401);
        }

        if ($user->status !== 'active') {
            return $this->denied('This account is not active.');
        }

        $client = $this->access->clientFrom($request);
        $this->access->ensureUserMayUse($user, $client);

        if ($request->bearerToken() !== null) {
            $token = $user->currentAccessToken();
            $abilities = $token instanceof PersonalAccessToken ? ($token->abilities ?? []) : [];

            if (! in_array(ClientPlatform::ability($client), $abilities, true)) {
                return $this->denied('This session is not valid for the selected CDM client.');
            }
        }

        return $next($request);
    }

    private function denied(string $message, int $status = 403): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}

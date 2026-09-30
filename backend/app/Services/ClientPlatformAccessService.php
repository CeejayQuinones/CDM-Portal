<?php

namespace App\Services;

use App\Exceptions\ClientPlatformException;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Http\Request;

class ClientPlatformAccessService
{
    /** @var array<string, list<string>> */
    private const ALLOWED_CLIENTS_BY_ROLE = [
        Role::REGISTRAR_STAFF => [ClientPlatform::DESKTOP],
        Role::ADMIN => [ClientPlatform::DESKTOP, ClientPlatform::WEB],
        Role::STUDENT => [ClientPlatform::WEB, ClientPlatform::MOBILE],
        Role::PROFESSOR => [ClientPlatform::WEB],
        Role::GUEST => [ClientPlatform::WEB],
    ];

    public function clientFrom(Request $request): string
    {
        $client = trim((string) $request->header(ClientPlatform::HEADER));

        if ($client === '') {
            throw new ClientPlatformException(400, 'The X-CDM-Client header is required.');
        }

        if (! in_array($client, ClientPlatform::ALL, true)) {
            throw new ClientPlatformException(400, 'The X-CDM-Client header must be web, desktop, or mobile.');
        }

        return $client;
    }

    public function ensureUserMayUse(User $user, string $client): void
    {
        $user->loadMissing('role');
        $role = $user->role?->role_name;

        if ($role !== null && in_array($client, self::ALLOWED_CLIENTS_BY_ROLE[$role] ?? [], true)) {
            return;
        }

        throw new ClientPlatformException(403, $this->denialMessage($role, $client));
    }

    /** @return list<string> */
    public function allowedClientsForRole(string $role): array
    {
        return self::ALLOWED_CLIENTS_BY_ROLE[$role] ?? [];
    }

    private function denialMessage(?string $role, string $client): string
    {
        if ($role === Role::REGISTRAR_STAFF) {
            return 'Registrar Staff accounts are available only through the CDM Desktop application.';
        }

        if ($role === Role::STUDENT && $client === ClientPlatform::DESKTOP) {
            return 'Student accounts cannot access the Registrar Desktop application.';
        }

        if ($client === ClientPlatform::MOBILE) {
            return 'This account is not permitted to use the Student mobile application.';
        }

        return 'This account is not permitted to use the '.ucfirst($client).' client.';
    }
}

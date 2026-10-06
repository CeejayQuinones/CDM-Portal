<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMonitoringAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureMonitoringAccessTest extends TestCase
{
    #[DataProvider('allowedRoles')]
    public function test_allowed_roles_can_enter_monitoring(string $roleName): void
    {
        $response = $this->runMiddleware($roleName);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[DataProvider('deniedRoles')]
    public function test_guest_cannot_enter_monitoring(string $roleName): void
    {
        try {
            $this->runMiddleware($roleName);
            $this->fail('Expected monitoring access to be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public static function allowedRoles(): array
    {
        return [[Role::STUDENT], [Role::PROFESSOR], [Role::REGISTRAR_STAFF], [Role::ADMIN]];
    }

    public static function deniedRoles(): array
    {
        return [[Role::GUEST]];
    }

    #[DataProvider('blockedStatuses')]
    public function test_inactive_accounts_cannot_enter_monitoring(string $status): void
    {
        try {
            $this->runMiddleware(Role::REGISTRAR_STAFF, $status);
            $this->fail('Expected inactive monitoring access to be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public static function blockedStatuses(): array
    {
        return [['inactive'], ['suspended']];
    }

    private function runMiddleware(string $roleName, string $status = 'active'): Response
    {
        $user = new User(['status' => $status]);
        $user->setRelation('role', new Role(['role_name' => $roleName]));
        $request = Request::create('/api/monitoring/early-warnings');
        $request->setUserResolver(fn () => $user);

        return app(EnsureMonitoringAccess::class)->handle($request, fn () => response('ok'));
    }
}

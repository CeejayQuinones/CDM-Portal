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
    public function test_guest_and_admin_cannot_enter_monitoring(string $roleName): void
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
        return [[Role::STUDENT], [Role::PROFESSOR], [Role::REGISTRAR_STAFF]];
    }

    public static function deniedRoles(): array
    {
        return [[Role::GUEST], [Role::ADMIN]];
    }

    private function runMiddleware(string $roleName): Response
    {
        $user = new User;
        $user->setRelation('role', new Role(['role_name' => $roleName]));
        $request = Request::create('/api/monitoring/early-warnings');
        $request->setUserResolver(fn () => $user);

        return app(EnsureMonitoringAccess::class)->handle($request, fn () => response('ok'));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AcademicMonitoringRemovalTest extends TestCase
{
    public function test_no_monitoring_routes_or_middleware_remain(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('monitoring', $route->uri());
            $this->assertNotContains('role.monitoring', $route->gatherMiddleware());
        }
    }

    public function test_removed_endpoints_are_not_found_on_every_client(): void
    {
        $this->withoutAutomaticClientPlatform();
        foreach (['web', 'mobile', 'desktop'] as $client) {
            foreach (['overview', 'early-warnings', 'my-risk', 'study-plans', 'students/1/study-plan', 'adviser-alerts', 'ai-status', 'my-risk-notifications'] as $path) {
                $this->getJson('/api/monitoring/'.$path, ['X-CDM-Client' => $client])->assertNotFound();
            }
            foreach (['support-plan', 'ai-help', 'risk-notifications'] as $action) {
                $this->postJson('/api/monitoring/students/1/'.$action, [], ['X-CDM-Client' => $client])->assertNotFound();
            }
            $this->patchJson('/api/monitoring/risk-notifications/1/read', [], ['X-CDM-Client' => $client])->assertNotFound();
        }
    }
}

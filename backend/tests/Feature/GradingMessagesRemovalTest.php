<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GradingMessagesRemovalTest extends TestCase
{
    public function test_message_routes_are_removed_while_grade_viewing_and_exports_remain(): void
    {
        $routes = collect(Route::getRoutes())->map(
            fn (LaravelRoute $route): array => [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
            ],
        );

        foreach ([
            'api/grading/professor/conversations',
            'api/grading/student/grades/{gradeSheet}/conversation',
            'api/grading/conversations/{gradeConversation}',
            'api/grading/conversations/{gradeConversation}/messages',
            'api/grading/messages/{gradeMessage}',
            'api/grading/messages/{gradeMessage}/attachment',
        ] as $removedUri) {
            $this->assertFalse($routes->contains('uri', $removedUri), $removedUri.' must not be registered.');
        }

        foreach ([
            'api/grading/student/grades',
            'api/grading/student/grades/export',
            'api/grading/classes',
            'api/grading/sheets/{gradeSheet}/submit',
            'api/grading/staff/students/{student}',
        ] as $preservedUri) {
            $this->assertTrue($routes->contains('uri', $preservedUri), $preservedUri.' must remain registered.');
        }
    }
}

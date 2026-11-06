<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\EnrollmentNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_student_receive_only_their_verified_portal_updates(): void
    {
        $guest = $this->user(Role::GUEST);
        $other = $this->user(Role::STUDENT);
        $guest->notify(new EnrollmentNotice('section_assigned', 'Your section is ready.', 41));
        $other->notify(new EnrollmentNotice('finalized', 'Another account update.', 42));

        Sanctum::actingAs($guest);
        $this->getJson('/api/portal-notices')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data.notices')
            ->assertJsonPath('data.notices.0.kind', 'official')
            ->assertJsonPath('data.notices.0.source', 'enrollment')
            ->assertJsonPath('data.notices.0.message', 'Your section is ready.')
            ->assertJsonMissing(['message' => 'Another account update.']);
    }

    public function test_buddy_notice_feed_is_not_available_to_staff_roles(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));

        $this->getJson('/api/portal-notices')->assertForbidden();
    }

    public function test_event_application_routes_are_not_registered(): void
    {
        Sanctum::actingAs($this->user(Role::STUDENT));

        foreach (['/api/events', '/api/event-promotions', '/api/event-reports', '/api/event-certificates/verify/'.str_repeat('a', 64)] as $path) {
            $this->getJson($path)->assertNotFound();
        }
    }

    private function user(string $role): User
    {
        $record = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::factory()->create(['role_id' => $record->id, 'status' => 'active']);
    }
}

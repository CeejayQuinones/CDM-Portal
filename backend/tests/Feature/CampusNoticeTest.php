<?php

namespace Tests\Feature;

use App\Models\CampusNotice;
use App\Models\CampusNoticeAuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\CampusWeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class CampusNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_create_edit_publish_and_archive_with_append_only_audit(): void
    {
        $admin = $this->user(Role::ADMIN);
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/campus-notices', $this->payload())
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->putJson("/api/campus-notices/{$id}", $this->payload(['title' => 'Updated official reminder']))
            ->assertOk()->assertJsonPath('data.title', 'Updated official reminder');
        $this->postJson("/api/campus-notices/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->putJson("/api/campus-notices/{$id}", $this->payload())->assertForbidden();
        $this->postJson("/api/campus-notices/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseCount('campus_notice_audit_events', 4);
        $event = CampusNoticeAuditEvent::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $event->update(['action' => 'changed']);
    }

    public function test_registrar_is_limited_to_authorized_categories_and_student_guest_are_read_only(): void
    {
        Sanctum::actingAs($this->user(Role::REGISTRAR_STAFF));
        $this->postJson('/api/campus-notices', $this->payload(['category' => 'enrollment']))->assertCreated();
        $this->postJson('/api/campus-notices', $this->payload([
            'category' => 'class_suspension', 'official_source' => 'Verified local order',
            'affected_scope' => 'All campus classes', 'effective_start_at' => now()->toIso8601String(),
            'effective_end_at' => now()->addHour()->toIso8601String(),
        ]))->assertForbidden();

        foreach ([Role::STUDENT, Role::GUEST] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/campus-notices')->assertForbidden();
            $this->postJson('/api/campus-notices', $this->payload())->assertForbidden();
        }
    }

    public function test_professor_cannot_access_management_or_buddy_feed(): void
    {
        Sanctum::actingAs($this->user(Role::PROFESSOR));
        $this->getJson('/api/campus-notices')->assertForbidden();
        $this->getJson('/api/portal-notices')->assertForbidden();
    }

    public function test_feed_filters_drafts_schedules_expiry_archives_and_audience(): void
    {
        Carbon::setTestNow('2026-10-22 02:00:00 UTC');
        $admin = $this->user(Role::ADMIN);
        $student = $this->user(Role::STUDENT);
        $guest = $this->user(Role::GUEST);
        $this->notice($admin, ['title' => 'Visible student notice']);
        $this->notice($admin, ['title' => 'Guest only', 'audiences' => [Role::GUEST]]);
        $this->notice($admin, ['title' => 'Future notice', 'publication_start_at' => now()->addHour()]);
        $this->notice($admin, ['title' => 'Expired notice', 'publication_end_at' => now()->subMinute()]);
        $this->notice($admin, ['title' => 'Archived notice', 'status' => 'archived']);
        $this->notice($admin, ['title' => 'Draft notice', 'status' => 'draft']);

        Sanctum::actingAs($student);
        $this->getJson('/api/portal-notices')->assertOk()
            ->assertJsonFragment(['title' => 'Visible student notice'])
            ->assertJsonMissing(['title' => 'Guest only'])
            ->assertJsonMissing(['title' => 'Future notice'])
            ->assertJsonMissing(['title' => 'Expired notice'])
            ->assertJsonMissing(['title' => 'Archived notice'])
            ->assertJsonMissing(['title' => 'Draft notice']);

        Sanctum::actingAs($guest);
        $this->getJson('/api/portal-notices')->assertOk()->assertJsonFragment(['title' => 'Guest only']);
    }

    public function test_class_suspension_requires_verified_details_and_plain_safe_content(): void
    {
        Sanctum::actingAs($this->user(Role::ADMIN));
        $this->postJson('/api/campus-notices', $this->payload(['category' => 'class_suspension']))
            ->assertUnprocessable()->assertJsonValidationErrors(['official_source', 'affected_scope', 'effective_start_at', 'effective_end_at']);
        $this->postJson('/api/campus-notices', $this->payload(['title' => '<script>alert(1)</script>']))
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/campus-notices', $this->payload(['target_route' => 'https://evil.example']))
            ->assertUnprocessable()->assertJsonValidationErrors('target_route');
    }

    public function test_removed_monitoring_links_cannot_be_created_and_historical_notices_keep_acknowledgement(): void
    {
        $admin = $this->user(Role::ADMIN);
        Sanctum::actingAs($admin);
        $this->postJson('/api/campus-notices', $this->payload(['target_route' => '/monitoring']))
            ->assertUnprocessable()->assertJsonValidationErrors('target_route');

        $notice = $this->notice($admin, ['target_route' => '/monitoring/study-plans', 'requires_acknowledgement' => true]);
        Sanctum::actingAs($this->user(Role::STUDENT));
        $response = $this->getJson('/api/portal-notices')->assertOk();
        $item = collect($response->json('data.notices'))->firstWhere('id', 'campus-'.$notice->id);
        $this->assertNotNull($item);
        $this->assertNull($item['link']);
        $this->assertTrue($item['acknowledgement']['required']);
        $this->assertFalse($item['acknowledgement']['acknowledged']);
        $this->assertSame('/monitoring/study-plans', $notice->fresh()->target_route);
    }

    public function test_recurring_reminders_use_asia_manila_weekday(): void
    {
        Carbon::setTestNow('2026-10-21 16:30:00 UTC'); // Thursday 00:30 in Manila.
        $admin = $this->user(Role::ADMIN);
        $student = $this->user(Role::STUDENT);
        $this->notice($admin, ['title' => 'Thursday reminder', 'is_recurring' => true, 'recurrence_weekdays' => ['thursday']]);
        $this->notice($admin, ['title' => 'Wednesday reminder', 'is_recurring' => true, 'recurrence_weekdays' => ['wednesday']]);

        Sanctum::actingAs($student);
        $this->getJson('/api/portal-notices')->assertOk()
            ->assertJsonFragment(['title' => 'Thursday reminder'])
            ->assertJsonMissing(['title' => 'Wednesday reminder']);
    }

    public function test_verified_suspensions_sort_ahead_of_other_notice_priorities(): void
    {
        $admin = $this->user(Role::ADMIN);
        $student = $this->user(Role::STUDENT);
        $this->notice($admin, ['title' => 'Routine reminder', 'priority' => 'low']);
        $this->notice($admin, ['title' => 'Urgent announcement', 'priority' => 'urgent']);
        $this->notice($admin, [
            'title' => 'Verified suspension', 'category' => 'class_suspension',
            'priority' => 'urgent', 'effective_start_at' => now()->subMinute(),
            'effective_end_at' => now()->addHour(), 'affected_scope' => 'All classes',
        ]);

        Sanctum::actingAs($student);
        $this->getJson('/api/portal-notices')->assertOk()
            ->assertJsonPath('data.notices.0.title', 'Verified suspension')
            ->assertJsonPath('data.notices.1.title', 'Urgent announcement');
    }

    public function test_required_notice_acknowledgement_is_account_scoped(): void
    {
        $admin = $this->user(Role::ADMIN);
        $student = $this->user(Role::STUDENT);
        $other = $this->user(Role::STUDENT);
        $notice = $this->notice($admin, ['requires_acknowledgement' => true]);

        Sanctum::actingAs($student);
        $this->postJson("/api/portal-notices/{$notice->id}/acknowledge")->assertOk();
        $this->getJson('/api/portal-notices')->assertJsonFragment([
            'id' => 'campus-'.$notice->id,
            'acknowledgement' => ['required' => true, 'acknowledged' => true],
        ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/portal-notices')->assertJsonFragment([
            'id' => 'campus-'.$notice->id,
            'acknowledgement' => ['required' => true, 'acknowledged' => false],
        ]);
    }

    public function test_weather_is_disabled_by_default_cached_when_enabled_and_omitted_on_failure(): void
    {
        $service = app(CampusWeatherService::class);
        config()->set('services.campus_weather.enabled', false);
        $this->assertNull($service->current());

        config()->set('services.campus_weather.enabled', true);
        Cache::flush();
        Http::fakeSequence()->push([
            'current' => ['temperature_2m' => 29.4, 'weather_code' => 61, 'time' => '2026-10-22T10:00'],
            'daily' => ['precipitation_probability_max' => [70], 'weather_code' => [61]],
        ])->push([], 500);
        $this->assertSame(70, $service->current()['rain_probability']);
        $this->assertSame(70, $service->current()['rain_probability']);
        Http::assertSentCount(1);

        Cache::flush();
        $this->assertNull($service->current());
        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Academic records reminder', 'message' => 'Please review your Portal records.',
            'category' => 'academic_reminder', 'priority' => 'normal',
            'audiences' => [Role::STUDENT, Role::GUEST], 'publication_start_at' => null,
            'publication_end_at' => null, 'effective_start_at' => null, 'effective_end_at' => null,
            'is_recurring' => false, 'recurrence_weekdays' => null,
            'requires_acknowledgement' => false, 'official_source' => null,
            'affected_scope' => null, 'target_route' => '/settings',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function notice(User $admin, array $overrides = []): CampusNotice
    {
        return CampusNotice::query()->create(array_replace([
            'title' => 'Published notice', 'message' => 'A verified campus update.',
            'category' => 'general', 'priority' => 'normal', 'audiences' => [Role::STUDENT],
            'status' => 'published', 'publication_start_at' => now()->subMinute(),
            'publication_end_at' => now()->addDay(), 'effective_start_at' => null,
            'effective_end_at' => null, 'is_recurring' => false, 'recurrence_weekdays' => null,
            'requires_acknowledgement' => false, 'official_source' => 'CDM Office',
            'affected_scope' => null, 'target_route' => '/settings',
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id,
            'published_by_user_id' => $admin->id, 'published_at' => now(), 'archived_at' => null,
        ], $overrides));
    }

    private function user(string $role): User
    {
        $record = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::factory()->create(['role_id' => $record->id, 'status' => 'active']);
    }
}

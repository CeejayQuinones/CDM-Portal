<?php

namespace Tests\Feature;

use App\Models\AppointmentBlockedDate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentAvailabilityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_holidays_can_be_reopened_without_disabling_other_holidays(): void
    {
        $holiday = AppointmentBlockedDate::query()
            ->whereDate('blocked_date', '2027-01-01')
            ->where('type', 'holiday')
            ->firstOrFail();
        $this->assertTrue($holiday->is_active);

        $role = Role::query()->firstOrCreate(['role_name' => Role::REGISTRAR_STAFF]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'status' => 'active']));

        $this->getJson('/api/registrar/appointment-availability/calendar?month=2027-01')
            ->assertOk()->assertJsonFragment(['date' => '2027-01-01', 'type' => 'holiday']);

        $this->patchJson('/api/registrar/appointment-blocked-dates/'.$holiday->id, ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson('/api/registrar/appointment-availability/calendar?month=2027-01')
            ->assertOk()->assertJsonPath('data.blocked_dates', []);
        $this->assertFalse($holiday->fresh()->is_active);
    }

    public function test_registrar_can_open_weekends_and_set_capacity_for_a_date(): void
    {
        $role = Role::query()->firstOrCreate(['role_name' => Role::REGISTRAR_STAFF]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'status' => 'active']));

        $this->patchJson('/api/registrar/appointment-availability/settings', [
            'block_saturday' => false,
            'block_sunday' => false,
        ])->assertOk()->assertJsonPath('data.block_saturday', false);

        $this->putJson('/api/registrar/appointment-availability/capacity/2027-01-04', ['capacity' => 20])
            ->assertOk()->assertJsonPath('data.capacity', 20);

        $this->getJson('/api/registrar/appointment-availability/calendar?month=2027-01')
            ->assertOk()->assertJsonPath('data.settings.block_sunday', false)
            ->assertJsonFragment(['date' => '2027-01-04', 'capacity' => 20]);
    }
}

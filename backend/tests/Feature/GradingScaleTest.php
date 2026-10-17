<?php

namespace Tests\Feature;

use App\Models\GradingScale;
use App\Models\Role;
use App\Models\User;
use App\Services\Grading\GradingScaleService;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class GradingScaleTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('grading-scale');
    }

    public function test_registrar_and_admin_can_manage_valid_ranges(): void
    {
        Sanctum::actingAs($this->fixture['registrar']);
        $created = $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/scales', [
            'min_percentage' => 85,
            'max_percentage' => 89.99,
            'grade_point' => 2,
            'remark' => 'Configured remark',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.remark', 'Configured remark')->json('data');

        $adminRole = Role::firstOrCreate(['role_name' => Role::ADMIN]);
        $admin = User::factory()->create(['role_id' => $adminRole->id]);
        Sanctum::actingAs($admin);
        $this->putJson('/api/grading/scales/'.$created['id'], [
            'min_percentage' => 85,
            'max_percentage' => 89.99,
            'grade_point' => 2.25,
            'remark' => 'Updated configured remark',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.grade_point', '2.25');
        $this->patchJson('/api/grading/scales/'.$created['id'].'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_overlapping_active_ranges_are_rejected_but_adjacent_ranges_are_allowed(): void
    {
        Sanctum::actingAs($this->fixture['registrar']);
        $request = fn (array $data) => $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/grading/scales', $data + [
            'grade_point' => 2,
            'remark' => 'Staff configured',
            'is_active' => true,
        ]);

        $request(['min_percentage' => 80, 'max_percentage' => 84.99])->assertCreated();
        $request(['min_percentage' => 85, 'max_percentage' => 89.99])->assertCreated();
        $request(['min_percentage' => 84, 'max_percentage' => 86])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('min_percentage')
            ->assertJsonPath('errors.min_percentage.0', 'This active percentage range overlaps an existing active range.');
    }

    public function test_lookup_returns_only_an_active_matching_range(): void
    {
        GradingScale::create(['min_percentage' => 85, 'max_percentage' => 89.99, 'grade_point' => 2, 'remark' => 'Configured remark', 'is_active' => true]);
        GradingScale::create(['min_percentage' => 90, 'max_percentage' => 100, 'grade_point' => 1, 'remark' => 'Inactive remark', 'is_active' => false]);
        $service = app(GradingScaleService::class);

        $this->assertSame(['grade_point' => 2.0, 'remarks' => 'Configured remark'], $service->resolve(88.47));
        $this->assertNull($service->resolve(95));
        $this->assertNull($service->resolve(70));
    }

    public function test_professor_and_student_cannot_manage_grading_scale(): void
    {
        foreach ([$this->fixture['professorUser'], $this->fixture['user']] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/grading/scales')->assertForbidden();
        }

    }

    public function test_guest_cannot_manage_grading_scale(): void
    {
        $this->getJson('/api/grading/scales')->assertUnauthorized();
    }
}

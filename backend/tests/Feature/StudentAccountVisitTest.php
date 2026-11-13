<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAccountVisitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_visit_request_is_not_identity_proof_and_registrar_controls_schedule(): void
    {
        $date = today()->addDays(3)->toDateString();
        $created = $this->postJson('/api/student-account/visit-requests', [
            'student_number' => '26-90001', 'full_name' => 'Test Student',
            'contact_email' => 'new@gmail.com', 'preferred_date' => $date,
            'preferred_time' => 'morning',
        ])->assertCreated()->json('data.reference');
        $this->assertDatabaseHas('student_account_visit_requests', [
            'reference' => $created, 'status' => 'requested', 'student_number' => '26-90001',
        ]);
        $this->getJson('/api/student-account/visit-requests/'.$created)->assertOk()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonMissingPath('data.contact_email');
        $this->getJson('/api/registrar/student-account/visit-requests')->assertUnauthorized();

        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $token = $registrar->createToken('registrar', ['client:desktop'])->plainTextToken;
        $this->withToken($token)->getJson('/api/registrar/student-account/visit-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->patchJson('/api/registrar/student-account/visit-requests/1', [
            'status' => 'scheduled', 'scheduled_date' => $date, 'scheduled_time' => '10:00',
        ])->assertStatus(428);
        $this->withToken($token)->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->withToken($token)->patchJson('/api/registrar/student-account/visit-requests/1', [
            'status' => 'scheduled', 'scheduled_date' => $date, 'scheduled_time' => '10:00',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->getJson('/api/student-account/visit-requests/'.$created)->assertOk()
            ->assertJsonPath('data.scheduled_date', $date);
        $this->withToken($token)->patchJson('/api/registrar/student-account/visit-requests/1', [
            'status' => 'resolved', 'physical_school_id_checked' => false,
        ])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/registrar/student-account/visit-requests/1', [
            'status' => 'resolved', 'physical_school_id_checked' => true,
        ])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseHas('student_account_visit_requests', [
            'reference' => $created, 'identity_checked_by_user_id' => $registrar->id,
        ]);
        $this->assertDatabaseCount('student_account_activations', 0);
    }

    public function test_visit_requires_future_date_and_does_not_reveal_student_existence(): void
    {
        $this->postJson('/api/student-account/visit-requests', [
            'student_number' => 'unknown', 'full_name' => 'Test Student',
            'contact_email' => 'new@gmail.com', 'preferred_date' => today()->toDateString(),
            'preferred_time' => 'morning',
        ])->assertUnprocessable();
        $this->postJson('/api/student-account/visit-requests', [
            'student_number' => 'unknown', 'full_name' => 'Test Student',
            'contact_email' => 'new@gmail.com', 'preferred_date' => today()->addDays(2)->toDateString(),
            'preferred_time' => 'morning',
        ])->assertCreated();
    }

    private function user(string $role): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => $role]);

        return User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    }
}

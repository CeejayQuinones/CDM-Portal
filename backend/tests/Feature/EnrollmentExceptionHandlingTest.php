<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class EnrollmentExceptionHandlingTest extends TestCase
{
    public function test_enrollment_model_not_found_is_a_normal_not_found_response(): void
    {
        Route::get('/api/enrollment/testing/not-found', function (): never {
            throw (new ModelNotFoundException)->setModel(Enrollment::class, [999]);
        });

        $this->getJson('/api/enrollment/testing/not-found')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Enrollment record not found.']);
    }

    public function test_unexpected_enrollment_errors_are_internal_errors_without_detail_leakage(): void
    {
        Route::get('/api/enrollment/testing/internal-error', function (): never {
            throw new RuntimeException('private database and stack detail');
        });

        $this->getJson('/api/enrollment/testing/internal-error')
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Enrollment could not be completed. Please reload and try again.',
            ]);
    }
}

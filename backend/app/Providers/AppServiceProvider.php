<?php

namespace App\Providers;

use App\Models\Admission\AdmissionApplicant;
use App\Models\Admission\AdmissionCycle;
use App\Policies\Admission\AdmissionApplicantPolicy;
use App\Policies\Admission\AdmissionCyclePolicy;
use App\Services\Enrollment\DatabaseEnrollmentPeriods;
use App\Services\Enrollment\EnrollmentPeriodResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EnrollmentPeriodResolver::class, DatabaseEnrollmentPeriods::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(AdmissionApplicant::class, AdmissionApplicantPolicy::class);
        Gate::policy(AdmissionCycle::class, AdmissionCyclePolicy::class);

    }
}

<?php

use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegistrarCabinetController;
use App\Http\Controllers\Api\RegistrarDashboardController;
use App\Http\Controllers\Api\RegistrarDocumentRequestController;
use App\Http\Controllers\Api\RegistrarDocumentTypeController;
use App\Http\Controllers\Api\StepUpAuthenticationController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentDocumentRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/registration/email-verification/send', [AuthController::class, 'sendEmailVerification'])
    ->middleware('throttle:3,1');
Route::post('/registration/email-verification/verify', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/step-up/verify', [StepUpAuthenticationController::class, 'verify'])
        ->middleware('throttle:5,1');

    Route::middleware('role.monitoring')->group(function (): void {
        Route::get('/monitoring/early-warnings', [MonitoringController::class, 'earlyWarnings']);
        Route::get('/monitoring/my-risk', [MonitoringController::class, 'myRisk']);
        Route::post('/monitoring/students/{student}/support-plan', [MonitoringController::class, 'supportPlan']);
        Route::get('/monitoring/study-plans', [MonitoringController::class, 'studyPlans']);
        Route::get('/monitoring/students/{student}/study-plan', [MonitoringController::class, 'studyPlan']);
        Route::get('/monitoring/adviser-alerts', [MonitoringController::class, 'adviserAlerts']);
        Route::get('/monitoring/ai-status', [MonitoringController::class, 'aiStatus']);
        Route::post('/monitoring/students/{student}/ai-help', [MonitoringController::class, 'aiHelp']);
    });

    Route::middleware('role.registrar-or-admin')->group(function (): void {
        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/bulk-options', [StudentController::class, 'bulkOptions']);
        Route::patch('/students/bulk', [StudentController::class, 'bulkUpdate'])->middleware('step-up');
        Route::get('/students/{student}', [StudentController::class, 'show']);
        Route::get('/students/{student}/documents', [StudentController::class, 'documents']);
        Route::patch('/students/{student}', [StudentController::class, 'update'])->middleware('step-up');
    });

    Route::middleware('role.student')->group(function (): void {
        Route::get('/document-types', [StudentDocumentRequestController::class, 'documentTypes']);
        Route::get('/document-requests', [StudentDocumentRequestController::class, 'index']);
        Route::post('/document-requests', [StudentDocumentRequestController::class, 'store']);
        Route::get('/document-requests/{documentRequest}', [StudentDocumentRequestController::class, 'show']);
        Route::post('/document-requests/{documentRequest}/appointments', [StudentDocumentRequestController::class, 'book']);
        Route::get('/appointment-slots', [StudentDocumentRequestController::class, 'slots']);
        Route::get('/appointment-overview', [StudentDocumentRequestController::class, 'appointmentOverview']);
        Route::get('/appointments', [StudentDocumentRequestController::class, 'appointments']);
        Route::get('/appointment-availability', [AppointmentAvailabilityController::class, 'show']);
    });

    Route::prefix('registrar')->middleware('role.registrar-staff')->group(function (): void {
        Route::get('/dashboard', RegistrarDashboardController::class);
        Route::get('/cabinets', [RegistrarCabinetController::class, 'index']);
        Route::post('/cabinets', [RegistrarCabinetController::class, 'store']);
        Route::get('/cabinets/{cabinet}', [RegistrarCabinetController::class, 'show']);
        Route::get('/cabinet-slots/{cabinetSlot}', [RegistrarCabinetController::class, 'showSlot']);
        Route::patch('/cabinet-slots/{cabinetSlot}', [RegistrarCabinetController::class, 'updateSlot'])
            ->middleware('step-up');
        Route::match(['put', 'patch'], '/students/{student}/record-location', [RegistrarCabinetController::class, 'updateStudentLocation'])
            ->middleware('step-up');
        Route::get('/document-types', [RegistrarDocumentTypeController::class, 'index']);
        Route::post('/document-types', [RegistrarDocumentTypeController::class, 'store']);
        Route::patch('/document-types/{documentType}', [RegistrarDocumentTypeController::class, 'update']);
        Route::get('/document-requests', [RegistrarDocumentRequestController::class, 'index']);
        Route::get('/document-requests/history', [RegistrarDocumentRequestController::class, 'history']);
        Route::get('/document-request-activity', [RegistrarDocumentRequestController::class, 'activity']);
        Route::get('/document-requests/{documentRequest}', [RegistrarDocumentRequestController::class, 'show']);
        Route::patch('/document-requests/{documentRequest}', [RegistrarDocumentRequestController::class, 'update']);
        Route::get('/appointments', [RegistrarDocumentRequestController::class, 'appointments']);
        Route::patch('/appointments/{appointment}', [RegistrarDocumentRequestController::class, 'updateAppointment']);
        Route::get('/appointment-availability/settings', [AppointmentAvailabilityController::class, 'settings']);
        Route::patch('/appointment-availability/settings', [AppointmentAvailabilityController::class, 'updateSettings']);
        Route::get('/appointment-blocked-dates', [AppointmentAvailabilityController::class, 'blockedDates']);
        Route::post('/appointment-blocked-dates', [AppointmentAvailabilityController::class, 'storeBlockedDate']);
        Route::patch('/appointment-blocked-dates/{appointmentBlockedDate}', [AppointmentAvailabilityController::class, 'updateBlockedDate']);
        Route::delete('/appointment-blocked-dates/{appointmentBlockedDate}', [AppointmentAvailabilityController::class, 'destroyBlockedDate']);
    });
});

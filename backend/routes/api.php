<?php

use App\Http\Controllers\Api\Admission\AdmissionApplicationController;
use App\Http\Controllers\Api\Admission\AdmissionIdentityController;
use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Enrollment\EnrollmentAcademicController;
use App\Http\Controllers\Api\Enrollment\EnrollmentStatusController;
use App\Http\Controllers\Api\Enrollment\EnrollmentWorkflowController;
use App\Http\Controllers\Api\EventAttendanceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\RegistrarAppointmentBlockedDateController;
use App\Http\Controllers\Api\RegistrarCabinetController;
use App\Http\Controllers\Api\RegistrarDashboardController;
use App\Http\Controllers\Api\RegistrarDocumentRequestController;
use App\Http\Controllers\Api\RegistrarDocumentTypeController;
use App\Http\Controllers\Api\RegistrarSettingsController;
use App\Http\Controllers\Api\RegistrarStudentDocumentController;
use App\Http\Controllers\Api\StepUpAuthenticationController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentDocumentRequestController;
use App\Http\Controllers\Api\StudentSettingsController;
use App\Http\Middleware\EnrollmentBoundary;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/registration/email-verification/send', [AuthController::class, 'sendEmailVerification'])
    ->middleware('throttle:3,1');
Route::post('/registration/email-verification/verify', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'client.platform'])->group(function (): void {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}/attendance', [EventAttendanceController::class, 'state'])->whereNumber('event')->middleware('role.registrar-or-admin');
    Route::post('/events/{event}/attendance/session', [EventAttendanceController::class, 'open'])->whereNumber('event')->middleware(['role.registrar-or-admin', 'throttle:10,1']);
    Route::post('/events/{event}/attendance/session/close', [EventAttendanceController::class, 'close'])->whereNumber('event')->middleware(['role.registrar-or-admin', 'throttle:10,1']);
    Route::post('/events/{event}/attendance/token', [EventAttendanceController::class, 'token'])->whereNumber('event')->middleware(['role.registrar-or-admin', 'throttle:30,1']);
    Route::post('/events/{event}/attendance/scan', [EventAttendanceController::class, 'scan'])->whereNumber('event')->middleware(['role.student', 'throttle:20,1']);
    Route::post('/events/{event}/attendance/manual', [EventAttendanceController::class, 'manual'])->whereNumber('event')->middleware(['role.registrar-or-admin', 'throttle:30,1']);
    Route::patch('/events/{event}/attendance/{attendance}', [EventAttendanceController::class, 'correct'])->whereNumber(['event', 'attendance'])->middleware(['role.registrar-or-admin', 'step-up', 'throttle:30,1']);
    Route::get('/events/options', [EventController::class, 'options'])->middleware('role.registrar-or-admin');
    Route::post('/events', [EventController::class, 'store'])->middleware(['role.registrar-or-admin', 'throttle:20,1']);
    Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event');
    Route::put('/events/{event}', [EventController::class, 'update'])->whereNumber('event')->middleware(['role.registrar-or-admin', 'throttle:30,1']);
    Route::post('/events/{event}/{action}', [EventController::class, 'transition'])->whereNumber('event')->whereIn('action', ['publish', 'cancel', 'archive'])->middleware(['role.registrar-or-admin', 'throttle:30,1']);
    Route::get('/enrollment/status', EnrollmentStatusController::class);
    Route::get('/enrollment/eligibility', EnrollmentStatusController::class);
    Route::prefix('enrollment')->middleware(EnrollmentBoundary::class)->controller(EnrollmentWorkflowController::class)->group(function (): void {
        Route::get('/periods', 'periods');
        Route::post('/periods', 'savePeriod')->middleware('throttle:30,1');
        Route::put('/periods/{id}', 'savePeriod')->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/applications/mine', 'mine');
        Route::get('/applications', 'index');
        Route::post('/applications', 'create')->middleware('throttle:20,1');
        Route::get('/applications/{id}', 'show')->whereNumber('id');
        Route::post('/applications/{id}/{action}', 'action')->whereNumber('id')->whereIn('action', ['save', 'submit', 'cancel', 'review', 'approve', 'reject'])->middleware('throttle:30,1');
        Route::post('/applications/{id}/documents', 'upload')->whereNumber('id')->middleware('throttle:10,1');
        Route::get('/applications/{id}/documents/{document}', 'download')->whereNumber(['id', 'document']);
    });
    Route::prefix('enrollment/academic')->middleware(EnrollmentBoundary::class)->controller(EnrollmentAcademicController::class)->group(function (): void {
        Route::get('/options', 'options');
        Route::get('/sections', 'sections');
        Route::post('/sections', 'saveSection')->middleware('throttle:30,1');
        Route::put('/sections/{id}', 'saveSection')->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/schedules', 'schedules');
        Route::post('/schedules', 'saveSchedule')->middleware('throttle:30,1');
        Route::put('/schedules/{id}', 'saveSchedule')->whereNumber('id')->middleware('throttle:30,1');
        Route::delete('/schedules/{id}', 'deleteSchedule')->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/applications/{id}', 'application')->whereNumber('id');
        Route::post('/applications/{id}/{action}', 'change')->whereNumber('id')->whereIn('action', ['subjects', 'assign', 'finalize'])->middleware('throttle:30,1');
        Route::get('/records', 'records');
        Route::get('/records/{id}', 'record')->whereNumber('id');
        Route::get('/professor', 'professor');
        Route::get('/professor/sections/{section}', 'professor')->whereNumber('section');
        Route::get('/notifications', 'notices');
        Route::patch('/notifications/{id}/read', 'readNotice');
    });
    Route::get('/admission/me', AdmissionIdentityController::class);
    Route::get('/admission/applications/availability', [AdmissionApplicationController::class, 'availability']);
    Route::post('/admission/applications', [AdmissionApplicationController::class, 'store'])->middleware('throttle:5,1');
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
        Route::post('/monitoring/students/{student}/ai-help', [MonitoringController::class, 'aiHelp'])->middleware('throttle:10,1');
        Route::post('/monitoring/students/{student}/risk-notifications', [MonitoringController::class, 'sendRiskNotification']);
        Route::get('/monitoring/my-risk-notifications', [MonitoringController::class, 'myRiskNotifications']);
        Route::patch('/monitoring/risk-notifications/{notification}/read', [MonitoringController::class, 'markRiskNotificationRead']);
    });

    Route::middleware('role.registrar-or-admin')->group(function (): void {
        Route::get('/registrar/settings', [RegistrarSettingsController::class, 'show']);
        Route::patch('/registrar/settings/profile', [RegistrarSettingsController::class, 'updateProfile']);
        Route::patch('/registrar/settings/contact', [RegistrarSettingsController::class, 'updateContact']);
        Route::post('/registrar/settings/avatar', [RegistrarSettingsController::class, 'updateAvatar']);
        Route::delete('/registrar/settings/avatar', [RegistrarSettingsController::class, 'removeAvatar']);
        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/bulk-options', [StudentController::class, 'bulkOptions']);
        Route::patch('/students/bulk', [StudentController::class, 'bulkUpdate'])->middleware('step-up');
        Route::get('/students/{student}', [StudentController::class, 'show']);
        Route::get('/students/{student}/history', [StudentController::class, 'history']);
        Route::get('/students/{student}/documents', [StudentController::class, 'documents']);
        Route::patch('/students/{student}', [StudentController::class, 'update'])->middleware('step-up');
    });

    Route::middleware('role.student')->group(function (): void {
        Route::get('/student/settings', [StudentSettingsController::class, 'show']);
        Route::patch('/student/settings/profile', [StudentSettingsController::class, 'updateProfile']);
        Route::patch('/student/settings/contact', [StudentSettingsController::class, 'updateContact']);
        Route::patch('/student/settings/preferences', [StudentSettingsController::class, 'updatePreferences']);
        Route::post('/student/settings/avatar', [StudentSettingsController::class, 'updateAvatar']);
        Route::delete('/student/settings/avatar', [StudentSettingsController::class, 'removeAvatar']);
        Route::get('/holidays', HolidayController::class);
        Route::get('/document-types', [StudentDocumentRequestController::class, 'documentTypes']);
        Route::get('/document-requests', [StudentDocumentRequestController::class, 'index']);
        Route::post('/document-requests', [StudentDocumentRequestController::class, 'store']);
        Route::get('/document-requests/{documentRequest}', [StudentDocumentRequestController::class, 'show']);
        Route::patch('/document-requests/{documentRequest}/cancel', [StudentDocumentRequestController::class, 'cancelDocumentRequest']);
    });

    Route::prefix('registrar')->middleware('role.registrar-staff')->group(function (): void {
        Route::get('/appointment-availability/settings', [AppointmentAvailabilityController::class, 'settings']);
        Route::patch('/appointment-availability/settings', [AppointmentAvailabilityController::class, 'updateSettings']);
        Route::get('/appointment-availability/calendar', [AppointmentAvailabilityController::class, 'calendar']);
        Route::put('/appointment-availability/capacity/{date}', [AppointmentAvailabilityController::class, 'updateCapacity']);
        Route::apiResource('appointment-blocked-dates', RegistrarAppointmentBlockedDateController::class)
            ->only(['index', 'store', 'update', 'destroy']);
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
        Route::post('/students/{student}/documents/analyze-all', [RegistrarStudentDocumentController::class, 'analyzeAll']);
        Route::post('/student-documents/{studentDocument}/upload', [RegistrarStudentDocumentController::class, 'upload']);
        Route::get('/student-documents/{studentDocument}/view', [RegistrarStudentDocumentController::class, 'view']);
        Route::get('/student-documents/{studentDocument}/download', [RegistrarStudentDocumentController::class, 'download']);
        Route::delete('/student-documents/{studentDocument}/file', [RegistrarStudentDocumentController::class, 'destroy'])
            ->middleware('step-up');
        Route::get('/document-requests', [RegistrarDocumentRequestController::class, 'index']);
        Route::get('/document-requests/history', [RegistrarDocumentRequestController::class, 'history']);
        Route::get('/document-request-activity', [RegistrarDocumentRequestController::class, 'activity']);
        Route::get('/document-requests/{documentRequest}', [RegistrarDocumentRequestController::class, 'show']);
        Route::patch('/document-requests/{documentRequest}', [RegistrarDocumentRequestController::class, 'update']);
        Route::post('/document-requests/{documentRequest}/appointment', [RegistrarDocumentRequestController::class, 'assignAppointment']);
        Route::post('/document-requests/verify-code', [RegistrarDocumentRequestController::class, 'verifyCode'])->middleware('throttle:10,1');
        Route::post('/document-requests/{documentRequest}/resend-claim-code', [RegistrarDocumentRequestController::class, 'resendClaimCode'])->middleware('throttle:3,10');
        Route::get('/appointments', [RegistrarDocumentRequestController::class, 'appointments']);
        Route::patch('/appointments/{appointment}', [RegistrarDocumentRequestController::class, 'updateAppointment']);
    });
});

require __DIR__.'/admission.php';

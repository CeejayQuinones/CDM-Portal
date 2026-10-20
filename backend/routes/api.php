<?php

use App\Http\Controllers\Api\AccountProvisioningController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\Admission\AdmissionApplicationController;
use App\Http\Controllers\Api\Admission\AdmissionIdentityController;
use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\Enrollment\EnrollmentAcademicController;
use App\Http\Controllers\Api\Enrollment\EnrollmentStatusController;
use App\Http\Controllers\Api\Enrollment\EnrollmentWorkflowController;
use App\Http\Controllers\Api\EventAttendanceController;
use App\Http\Controllers\Api\EventCertificateController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventExperienceController;
use App\Http\Controllers\Api\EventModeratorRequestController;
use App\Http\Controllers\Api\EventPersonnelController;
use App\Http\Controllers\Api\EventPromotionController;
use App\Http\Controllers\Api\EventReportController;
use App\Http\Controllers\Api\GradingController;
use App\Http\Controllers\Api\GradingExperienceController;
use App\Http\Controllers\Api\GradingScaleController;
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
use App\Http\Controllers\Api\StudentRecordProductivityController;
use App\Http\Controllers\Api\StudentRecordReportController;
use App\Http\Controllers\Api\StudentRecordRequirementController;
use App\Http\Controllers\Api\StudentSettingsController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Middleware\EnrollmentBoundary;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/registration/email-verification/send', [AuthController::class, 'sendEmailVerification'])
    ->middleware('throttle:3,1');
Route::post('/registration/email-verification/verify', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:5,1');
Route::get('/event-certificates/verify/{code}', [EventCertificateController::class, 'verify'])->where('code', '[a-f0-9]{64}')->middleware('throttle:30,1');

Route::middleware(['auth:sanctum', 'client.platform'])->group(function (): void {
    Route::prefix('admin')->middleware('role.admin')->group(function (): void {
        Route::get('/dashboard', AdminDashboardController::class);
        Route::get('/registrars', [AccountProvisioningController::class, 'registrars']);
        Route::post('/registrars', [AccountProvisioningController::class, 'storeRegistrar']);
        Route::put('/registrars/{registrarStaff}', [AccountProvisioningController::class, 'updateRegistrar']);
        Route::patch('/registrars/{registrarStaff}/status', [AccountProvisioningController::class, 'registrarStatus']);
    });
    Route::prefix('registrar')->middleware('role.registrar-staff')->group(function (): void {
        Route::get('/professors', [AccountProvisioningController::class, 'professors']);
        Route::post('/professors', [AccountProvisioningController::class, 'storeProfessor']);
        Route::put('/professors/{professor}', [AccountProvisioningController::class, 'updateProfessor']);
        Route::patch('/professors/{professor}/status', [AccountProvisioningController::class, 'professorStatus']);
    });
    Route::get('/student/dashboard', [DashboardSummaryController::class, 'student'])->middleware('role.student');
    Route::get('/professor/dashboard', [DashboardSummaryController::class, 'professor'])->middleware(['role.professor', 'professor.web']);
    Route::middleware('event.platform:promotion')->group(function (): void {
        Route::get('/event-promotions', [EventPromotionController::class, 'index'])->middleware('throttle:60,1');
        Route::get('/event-promotions/{event}', [EventPromotionController::class, 'show'])->whereNumber('event')->middleware('throttle:60,1');
    });
    Route::prefix('grading')->middleware('professor.web')->group(function (): void {
        Route::middleware('role.registrar-or-admin')->group(function (): void {
            Route::get('/scales', [GradingScaleController::class, 'index']);
            Route::post('/scales', [GradingScaleController::class, 'store']);
            Route::put('/scales/{gradingScale}', [GradingScaleController::class, 'update']);
            Route::patch('/scales/{gradingScale}/status', [GradingScaleController::class, 'status']);
            Route::get('/periods', [GradingController::class, 'periods']);
            Route::post('/periods', [GradingController::class, 'storePeriod']);
            Route::put('/periods/{gradePeriodSchedule}', [GradingController::class, 'updatePeriod']);
            Route::delete('/periods/{gradePeriodSchedule}', [GradingController::class, 'destroyPeriod']);
            Route::patch('/periods/{gradePeriodSchedule}/archive', [GradingController::class, 'archivePeriod']);
            Route::get('/reviews', [GradingController::class, 'reviews']);
            Route::get('/reviews/{gradeSheet}', [GradingController::class, 'reviewDetail']);
            Route::post('/reviews/{gradeSheet}/{action}', [GradingController::class, 'review'])->whereIn('action', ['approve', 'return'])->middleware('step-up:staff');
            Route::get('/releases', [GradingController::class, 'releases']);
            Route::post('/releases', [GradingController::class, 'storeRelease']);
            Route::put('/releases/{gradeReleaseSchedule}', [GradingController::class, 'updateRelease']);
            Route::post('/releases/{gradeReleaseSchedule}/execute', [GradingController::class, 'executeRelease'])->middleware('step-up:staff');
            Route::get('/staff/students/{student}', [GradingExperienceController::class, 'staffStudentGrades']);
            Route::get('/staff/students/{student}/export', [GradingExperienceController::class, 'staffStudentExport'])->middleware('throttle:20,1');
            Route::get('/staff/sheets/{gradeSheet}/export', [GradingExperienceController::class, 'classExport'])->middleware('throttle:20,1');
        });
        Route::middleware('role.professor')->group(function (): void {
            Route::get('/classes', [GradingController::class, 'classes']);
            Route::post('/classes/{sectionSubject}/workspace', [GradingController::class, 'workspace']);
            Route::post('/sheets/{gradeSheet}/assessments', [GradingController::class, 'storeAssessment']);
            Route::post('/sheets/{gradeSheet}/assessments/bulk', [GradingController::class, 'storeAssessmentsBulk']);
            Route::put('/sheets/{gradeSheet}/assessments/{gradeAssessment}', [GradingController::class, 'updateAssessment']);
            Route::patch('/sheets/{gradeSheet}/assessments/{gradeAssessment}/status', [GradingController::class, 'assessmentStatus']);
            Route::put('/sheets/{gradeSheet}/scores', [GradingController::class, 'scores']);
            Route::put('/sheets/{gradeSheet}/weights/{period}', [GradingController::class, 'weights']);
            Route::put('/sheets/{gradeSheet}/final-weights', [GradingController::class, 'finalWeights']);
            Route::get('/sheets/{gradeSheet}/readiness', [GradingController::class, 'readiness']);
            Route::post('/sheets/{gradeSheet}/submit', [GradingController::class, 'submit']);
            Route::get('/professor/conversations', [GradingExperienceController::class, 'professorConversations']);
        });
        Route::middleware('role.student')->group(function (): void {
            Route::get('/student/grades', [GradingExperienceController::class, 'studentGrades']);
            Route::get('/student/grades/export', [GradingExperienceController::class, 'studentExport'])->middleware('throttle:20,1');
            Route::post('/student/grades/{gradeSheet}/conversation', [GradingExperienceController::class, 'createConversation'])->middleware('throttle:30,1');
        });
        Route::get('/conversations/{gradeConversation}', [GradingExperienceController::class, 'conversation']);
        Route::post('/conversations/{gradeConversation}/messages', [GradingExperienceController::class, 'sendMessage'])->middleware('throttle:30,1');
        Route::delete('/messages/{gradeMessage}', [GradingExperienceController::class, 'unsendMessage'])->middleware('throttle:30,1');
        Route::get('/messages/{gradeMessage}/attachment', [GradingExperienceController::class, 'attachment'])->middleware('throttle:60,1');
    });
    Route::middleware('event.platform')->group(function (): void {
        Route::get('/events/profile', [EventExperienceController::class, 'profile']);
        Route::get('/events/notifications', [EventExperienceController::class, 'notifications']);
        Route::post('/events/notifications/{notification}/read', [EventExperienceController::class, 'readNotification'])->middleware('throttle:60,1');
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event');
        Route::get('/events/{event}/certificate', [EventCertificateController::class, 'status'])->whereNumber('event');
        Route::post('/events/{event}/certificate/issue', [EventCertificateController::class, 'issue'])->whereNumber('event')->middleware('throttle:10,1');
        Route::get('/events/{event}/certificate/download', [EventCertificateController::class, 'download'])->whereNumber('event')->middleware('throttle:10,1');
        Route::get('/events/{event}/attendance', [EventAttendanceController::class, 'state'])->whereNumber('event');
        Route::post('/events/{event}/attendance/manual', [EventAttendanceController::class, 'manual'])->whereNumber('event')->middleware('throttle:30,1');
        Route::post('/events/{event}/attendance/operator-scan', [EventAttendanceController::class, 'operatorScan'])->whereNumber('event')->middleware('throttle:30,1');
        Route::post('/events/{event}/attendance/{attendance}/checkout', [EventAttendanceController::class, 'checkout'])->whereNumber(['event', 'attendance'])->middleware('throttle:30,1');
        Route::get('/events/{event}/attendance/verifications', [EventAttendanceController::class, 'verifications'])->whereNumber('event');
        Route::post('/events/{event}/attendance/verifications/{verification}/{decision}', [EventAttendanceController::class, 'reviewVerification'])
            ->whereNumber(['event', 'verification'])->whereIn('decision', ['approve', 'reject'])->middleware('throttle:30,1');
    });
    Route::middleware('event.platform:mobile')->group(function (): void {
        Route::post('/events/{event}/moderator-requests', [EventModeratorRequestController::class, 'store'])->whereNumber('event')->middleware('throttle:10,1');
        Route::post('/events/{event}/attendance/scan', [EventAttendanceController::class, 'scan'])->whereNumber('event')->middleware(['role.student', 'throttle:20,1']);
        Route::post('/events/{event}/attendance/participant-qr', [EventAttendanceController::class, 'participantQr'])->whereNumber('event')->middleware(['role.student', 'throttle:30,1']);
    });
    Route::middleware('event.platform:desktop')->group(function (): void {
        Route::get('/event-reports', [EventReportController::class, 'index']);
        Route::get('/event-reports/{event}', [EventReportController::class, 'show'])->whereNumber('event');
        Route::get('/event-reports/{event}/export', [EventReportController::class, 'export'])->whereNumber('event')->middleware('throttle:10,1');
        Route::post('/events/{event}/attendance/session', [EventAttendanceController::class, 'open'])->whereNumber('event')->middleware('throttle:10,1');
        Route::post('/events/{event}/attendance/session/close', [EventAttendanceController::class, 'close'])->whereNumber('event')->middleware('throttle:10,1');
        Route::post('/events/{event}/attendance/token', [EventAttendanceController::class, 'token'])->whereNumber('event')->middleware('throttle:30,1');
        Route::post('/events/{event}/attendance/static-token', [EventAttendanceController::class, 'staticToken'])->whereNumber('event')->middleware('throttle:10,1');
        Route::patch('/events/{event}/attendance/{attendance}', [EventAttendanceController::class, 'correct'])->whereNumber(['event', 'attendance'])->middleware(['step-up:staff', 'throttle:30,1']);
        Route::get('/events/options', [EventController::class, 'options']);
        Route::put('/events/{event}/certificate-settings', [EventCertificateController::class, 'configure'])->whereNumber('event')->middleware('throttle:20,1');
        Route::post('/events', [EventController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/events/{event}', [EventController::class, 'update'])->whereNumber('event')->middleware('throttle:30,1');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->whereNumber('event')->middleware('throttle:20,1');
        Route::post('/events/{event}/{action}', [EventController::class, 'transition'])->whereNumber('event')->whereIn('action', ['publish', 'cancel', 'archive'])->middleware('throttle:30,1');
        Route::get('/events/{event}/personnel', [EventPersonnelController::class, 'index'])->whereNumber('event');
        Route::get('/events/{event}/moderator-requests', [EventModeratorRequestController::class, 'index'])->whereNumber('event');
        Route::post('/events/{event}/moderator-requests/{moderatorRequest}/{action}', [EventModeratorRequestController::class, 'review'])
            ->whereNumber(['event', 'moderatorRequest'])->whereIn('action', ['approve', 'reject', 'revoke'])->middleware('throttle:30,1');
        Route::post('/events/{event}/personnel', [EventPersonnelController::class, 'store'])->whereNumber('event')->middleware('throttle:30,1');
        Route::put('/events/{event}/personnel/{assignment}', [EventPersonnelController::class, 'update'])->whereNumber(['event', 'assignment'])->middleware('throttle:30,1');
        Route::post('/events/{event}/personnel/{assignment}/revoke', [EventPersonnelController::class, 'revoke'])->whereNumber(['event', 'assignment'])->middleware('throttle:30,1');
    });
    Route::get('/enrollment/status', EnrollmentStatusController::class)->middleware('professor.web');
    Route::get('/enrollment/eligibility', EnrollmentStatusController::class)->middleware('professor.web');
    Route::prefix('enrollment')->middleware([EnrollmentBoundary::class, 'professor.web'])->controller(EnrollmentWorkflowController::class)->group(function (): void {
        Route::get('/periods', 'periods');
        Route::post('/academic-years', 'saveAcademicYear')->middleware('throttle:30,1');
        Route::put('/academic-years/{id}', 'saveAcademicYear')->whereNumber('id')->middleware('throttle:30,1');
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
    Route::prefix('enrollment/academic')->middleware([EnrollmentBoundary::class, 'professor.web'])->controller(EnrollmentAcademicController::class)->group(function (): void {
        Route::get('/options', 'options');
        Route::get('/sections', 'sections');
        Route::post('/sections', 'saveSection')->middleware('throttle:30,1');
        Route::put('/sections/{id}', 'saveSection')->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/subjects', 'subjects');
        Route::post('/subjects', 'saveSubject')->middleware('throttle:30,1');
        Route::put('/subjects/{id}', 'saveSubject')->whereNumber('id')->middleware('throttle:30,1');
        Route::post('/subjects/{id}/{action}', 'subjectStatus')->whereNumber('id')->whereIn('action', ['archive', 'reactivate'])->middleware('throttle:30,1');
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
        Route::get('/monitoring/overview', [MonitoringController::class, 'earlyWarnings']);
        Route::get('/monitoring/early-warnings', [MonitoringController::class, 'earlyWarnings']);
        Route::get('/monitoring/my-risk', [MonitoringController::class, 'myRisk']);
        Route::post('/monitoring/students/{student}/support-plan', [MonitoringController::class, 'supportPlan']);
        Route::get('/monitoring/study-plans', [MonitoringController::class, 'studyPlans']);
        Route::get('/monitoring/students/{student}/study-plan', [MonitoringController::class, 'studyPlan']);
        Route::get('/monitoring/adviser-alerts', [MonitoringController::class, 'adviserAlerts']);
        Route::get('/monitoring/ai-status', [MonitoringController::class, 'aiStatus']);
        Route::post('/monitoring/students/{student}/ai-help', [MonitoringController::class, 'aiHelp'])->middleware('throttle:10,1');
        Route::post('/monitoring/students/{student}/risk-notifications', [MonitoringController::class, 'sendRiskNotification'])->middleware('throttle:10,1');
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
        Route::get('/students/quick-search', [StudentController::class, 'quickSearch'])->middleware('throttle:60,1');
        Route::get('/student-records/productivity', [StudentRecordProductivityController::class, 'show']);
        Route::post('/student-records/saved-filters', [StudentRecordProductivityController::class, 'storeFilter']);
        Route::put('/student-records/saved-filters/{savedFilter}', [StudentRecordProductivityController::class, 'updateFilter']);
        Route::delete('/student-records/saved-filters/{savedFilter}', [StudentRecordProductivityController::class, 'destroyFilter']);
        Route::put('/student-records/preferences', [StudentRecordProductivityController::class, 'preferences']);
        Route::get('/student-record-reports/options', [StudentRecordReportController::class, 'options']);
        Route::get('/student-record-reports/{reportType}', [StudentRecordReportController::class, 'index']);
        Route::get('/student-record-reports/{reportType}/export', [StudentRecordReportController::class, 'export'])
            ->middleware('throttle:20,1');
        Route::get('/student-record-reports/{reportType}/export/excel', [StudentRecordReportController::class, 'excel'])
            ->middleware('throttle:10,1');
        Route::get('/student-record-reports/{reportType}/export/pdf', [StudentRecordReportController::class, 'pdf'])
            ->middleware('throttle:10,1');
        Route::get('/students/bulk-options', [StudentController::class, 'bulkOptions']);
        Route::patch('/students/bulk', [StudentController::class, 'bulkUpdate'])->middleware('step-up');
        Route::get('/students/{student}', [StudentController::class, 'show']);
        Route::get('/students/{student}/history', [StudentController::class, 'history']);
        Route::get('/students/{student}/documents', [StudentController::class, 'documents']);
        Route::get('/registrar/student-documents/{studentDocument}/view', [RegistrarStudentDocumentController::class, 'view']);
        Route::get('/registrar/student-documents/{studentDocument}/download', [RegistrarStudentDocumentController::class, 'download']);
        Route::patch('/student-documents/{studentDocument}/{action}', [StudentRecordRequirementController::class, 'review'])
            ->whereIn('action', ['verify', 'needs-resubmission']);
        Route::patch('/students/{student}/record-requirements/{documentType}/{action}', [StudentRecordRequirementController::class, 'physical'])
            ->whereIn('action', ['require', 'receive', 'reopen']);
        Route::patch('/students/{student}', [StudentController::class, 'update'])->middleware('step-up');
    });

    Route::middleware('role.student')->group(function (): void {
        Route::get('/student/settings', [StudentSettingsController::class, 'show']);
        Route::patch('/student/settings/profile', [StudentSettingsController::class, 'updateProfile']);
        Route::patch('/student/settings/contact', [StudentSettingsController::class, 'updateContact']);
        Route::patch('/student/settings/preferences', [StudentSettingsController::class, 'updatePreferences']);
        Route::post('/student/settings/avatar', [StudentSettingsController::class, 'updateAvatar']);
        Route::delete('/student/settings/avatar', [StudentSettingsController::class, 'removeAvatar']);
        Route::post('/student/settings/record-requirements/{documentType}/upload', [StudentRecordRequirementController::class, 'studentUpload'])
            ->middleware('throttle:10,1');
        Route::get('/student/settings/student-documents/{studentDocument}/view', [StudentRecordRequirementController::class, 'studentView']);
        Route::get('/student/settings/student-documents/{studentDocument}/download', [StudentRecordRequirementController::class, 'studentDownload']);
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

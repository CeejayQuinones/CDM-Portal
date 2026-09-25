<?php

use App\Http\Controllers\Api\Admission\AdmissionWorkflowController as Workflow;
use App\Http\Middleware\AdmissionBoundary;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', AdmissionBoundary::class.':configure'])->prefix('admission/admin')->group(function () {
    Route::get('cycles', [Workflow::class, 'cycles']);
    Route::post('cycles', [Workflow::class, 'saveCycle']);
    Route::put('cycles/{id}', [Workflow::class, 'saveCycle'])->whereNumber('id');
    Route::get('questions', [Workflow::class, 'questions']);
    Route::post('questions', [Workflow::class, 'saveQuestion']);
    Route::put('questions/{id}', [Workflow::class, 'saveQuestion'])->whereNumber('id');
    Route::delete('questions/{id}', [Workflow::class, 'deleteQuestion'])->whereNumber('id');
    Route::get('programs', [Workflow::class, 'programs']);
    Route::put('programs/{id}', [Workflow::class, 'saveProgram'])->whereNumber('id');
});
Route::middleware(['auth:sanctum', AdmissionBoundary::class.':read'])->prefix('admission')->group(function () {
    Route::get('exam', [Workflow::class, 'exam']);
    Route::get('result', [Workflow::class, 'result']);
    Route::get('recommendation', [Workflow::class, 'recommendation']);
});
Route::middleware(['auth:sanctum', AdmissionBoundary::class.':write'])->prefix('admission')->group(function () {
    Route::post('exam/start', [Workflow::class, 'start'])->middleware('throttle:10,1');
    Route::put('exam/{id}/answers', [Workflow::class, 'save'])->whereUuid('id');
    Route::post('exam/{id}/submit', [Workflow::class, 'submit'])->whereUuid('id');
    Route::post('recommendation', [Workflow::class, 'recommendation'])->middleware('throttle:5,1');
});
Route::middleware(['auth:sanctum', AdmissionBoundary::class.':review'])->prefix('admission/registrar')->group(function () {
    Route::get('applicants/{id}/conversion', [Workflow::class, 'conversion'])->whereNumber('id');
    Route::post('applicants/{id}/accept', [Workflow::class, 'acceptApplicant'])->whereNumber('id')->middleware(['step-up', 'throttle:10,1']);
    Route::post('applicants/{id}/convert', [Workflow::class, 'convertApplicant'])->whereNumber('id')->middleware(['step-up', 'throttle:10,1']);
    Route::get('applicants', [Workflow::class, 'applicants']);
    Route::get('results', [Workflow::class, 'results']);
    Route::get('history', [Workflow::class, 'history']);
    foreach (['approve', 'publish', 'correct', 'override'] as $action) {
        $route = Route::post('results/'.$action, [Workflow::class, 'review'])->defaults('action', $action);
        if (in_array($action, ['correct', 'override'], true)) {
            $route->middleware('step-up');
        }
    }
});

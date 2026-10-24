<?php

use App\Http\Controllers\BaController;
use App\Http\Controllers\BrowserProjectController;
use App\Http\Controllers\BrowserSessionController;
use App\Http\Controllers\LifecycleTaskController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\QaController;
use App\Http\Controllers\RequirementController;
use App\Http\Middleware\AuthenticateBrowserSession;
use Illuminate\Support\Facades\Route;

Route::get('session', [BrowserSessionController::class, 'show']);
Route::post('login', [BrowserSessionController::class, 'login']);
Route::post('logout', [BrowserSessionController::class, 'logout']);

Route::middleware(AuthenticateBrowserSession::class)->group(function (): void {
    Route::get('projects', [BrowserProjectController::class, 'index']);
    Route::prefix('projects/{project}')->whereUuid('project')->group(function (): void {
        Route::get('capabilities', [BrowserProjectController::class, 'capabilities']);
        foreach (['requirements' => RequirementController::class, 'lifecycle-tasks' => LifecycleTaskController::class, 'progress' => ProgressController::class] as $path => $controller) {
            Route::get($path, [$controller, 'handle']);
        }
        foreach (['verification', 'queue'] as $operation) {
            Route::get('qa/'.$operation, [QaController::class, 'read'])->defaults('qa_operation', $operation);
        }
        Route::get('qa/history/{kind}/{subject}', [QaController::class, 'read'])->whereUuid('subject')->defaults('qa_operation', 'history');
        Route::get('ba/queue', [BaController::class, 'read'])->defaults('ba_operation', 'queue');
        Route::get('ba/history/{clarification}', [BaController::class, 'read'])->whereUuid('clarification')->defaults('ba_operation', 'history');
        Route::get('management/queue', [ManagementController::class, 'read'])->defaults('management_operation', 'queue');
        Route::get('management/history/{action}', [ManagementController::class, 'read'])->whereUuid('action')->defaults('management_operation', 'history');
    });
});

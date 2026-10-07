<?php

// All routes in this file inherit authentication. Domain permissions are added in T03–T05.

use App\Http\Controllers\AcceptanceController;
use App\Http\Controllers\BaselineController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\DeveloperAnalysisController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\IdentityMappingController;
use App\Http\Controllers\IngestionController;
use App\Http\Controllers\LifecycleEvidenceController;
use App\Http\Controllers\LifecycleTaskController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QaController;
use App\Http\Controllers\RequirementController;
use Illuminate\Support\Facades\Route;

foreach (['employees', 'departments'] as $resource) {
    Route::match(['GET', 'POST'], '/v1/'.$resource, [OrganizationController::class, 'handle'])->defaults('organization_resource', $resource);
    Route::patch('/v1/'.$resource.'/{id}', [OrganizationController::class, 'handle'])->whereUuid('id')->defaults('organization_resource', $resource);
}

Route::get('/v1/me', [IdentityController::class, 'me']);
Route::post('/v1/source-instances', [IdentityMappingController::class, 'handle'])->defaults('identity_operation', 'source');
Route::post('/v1/source-instances/{source}/identities', [IdentityMappingController::class, 'handle'])->whereUuid('source')->defaults('identity_operation', 'identity');
Route::post('/v1/identities/{identity}/mappings', [IdentityMappingController::class, 'handle'])->whereUuid('identity')->defaults('identity_operation', 'propose');
Route::patch('/v1/identity-mappings/{mapping}', [IdentityMappingController::class, 'handle'])->whereUuid('mapping')->defaults('identity_operation', 'review');
Route::get('/v1/identity-mappings/{mapping}/history', [IdentityMappingController::class, 'handle'])->whereUuid('mapping')->defaults('identity_operation', 'history');
Route::get('/v1/identities/{identity}/attribution', [IdentityMappingController::class, 'handle'])->whereUuid('identity')->defaults('identity_operation', 'attribution');
Route::post('/v1/projects', [ProjectController::class, 'handle']);
Route::match(['GET', 'PATCH'], '/v1/projects/{project}', [ProjectController::class, 'handle'])->whereUuid('project');
foreach (['roles', 'memberships'] as $resource) {
    Route::match($resource === 'roles' ? ['GET', 'POST'] : ['POST'], '/v1/projects/{project}/'.$resource, [ProjectController::class, 'handle'])->whereUuid('project')->defaults('project_resource', $resource);
    Route::patch('/v1/projects/{project}/'.$resource.'/{id}', [ProjectController::class, 'handle'])->whereUuid('project')->whereUuid('id')->defaults('project_resource', $resource);
}

Route::match(['GET', 'POST'], '/v1/projects/{project}/connectors', [ConnectorController::class, 'handle'])->whereUuid('project');
Route::get('/v1/connectors/{connector}', [ConnectorController::class, 'handle'])->whereUuid('connector');
Route::post('/v1/connectors/{connector}/receipts', [IngestionController::class, 'accept'])->whereUuid('connector');

Route::match(['GET', 'POST'], '/v1/projects/{project}/requirements', [RequirementController::class, 'handle'])->whereUuid('project');
Route::patch('/v1/projects/{project}/requirement-drafts/{draft}', [RequirementController::class, 'handle'])->whereUuid('project')->whereUuid('draft')->defaults('lifecycle_operation', 'update');
Route::post('/v1/projects/{project}/requirement-drafts/{draft}/approve', [RequirementController::class, 'handle'])->whereUuid('project')->whereUuid('draft')->defaults('lifecycle_operation', 'approve');
Route::post('/v1/projects/{project}/dod-policies', [RequirementController::class, 'handle'])->whereUuid('project')->defaults('lifecycle_operation', 'policy');

Route::match(['GET', 'POST'], '/v1/projects/{project}/lifecycle-tasks', [LifecycleTaskController::class, 'handle'])->whereUuid('project');
Route::patch('/v1/projects/{project}/lifecycle-tasks/{task}', [LifecycleTaskController::class, 'handle'])->whereUuid('project')->whereUuid('task')->defaults('task_operation', 'revise');
Route::post('/v1/projects/{project}/lifecycle-tasks/{task}/dependencies', [LifecycleTaskController::class, 'handle'])->whereUuid('project')->whereUuid('task')->defaults('task_operation', 'dependency');
Route::post('/v1/projects/{project}/evidence-links', [LifecycleEvidenceController::class, 'handle'])->whereUuid('project');
Route::post('/v1/projects/{project}/evidence-links/{link}/reviews', [LifecycleEvidenceController::class, 'handle'])->whereUuid('project')->whereUuid('link')->defaults('evidence_operation', 'review');
Route::post('/v1/projects/{project}/qa-observations', [LifecycleEvidenceController::class, 'handle'])->whereUuid('project')->defaults('evidence_operation', 'qa');
Route::post('/v1/projects/{project}/baselines', [BaselineController::class, 'handle'])->whereUuid('project');
Route::post('/v1/projects/{project}/baseline-close', [BaselineController::class, 'handle'])->whereUuid('project')->defaults('baseline_operation', 'close');
Route::post('/v1/projects/{project}/acceptance-decisions', [AcceptanceController::class, 'handle'])->whereUuid('project');
Route::get('/v1/projects/{project}/progress', [ProgressController::class, 'handle'])->whereUuid('project');
Route::post('/v1/projects/{project}/analysis-runs', [DeveloperAnalysisController::class, 'requestRun'])->whereUuid('project');
Route::get('/v1/analysis-runs/{run}', [DeveloperAnalysisController::class, 'run'])->whereUuid('run');
Route::get('/v1/findings/{finding}', [DeveloperAnalysisController::class, 'finding'])->whereUuid('finding');
Route::post('/v1/findings/{finding}/reviews', [DeveloperAnalysisController::class, 'review'])->whereUuid('finding');

Route::prefix('/v1/projects/{project}/qa')->whereUuid('project')->group(function (): void {
    foreach (['fixtures' => 'fixture', 'plan-entries' => 'entry', 'plans' => 'plan', 'executions' => 'execution', 'defects' => 'defect', 'handoffs' => 'handoff'] as $path => $operation) {
        Route::post('/'.$path, [QaController::class, 'create'])->defaults('qa_operation', $operation);
    }
    foreach (['definitions/{definition}/reviews' => ['definition.review', 'definition'], 'plans/{plan}/reviews' => ['plan.review', 'plan'], 'defects/{defect}/events' => ['defect.transition', 'defect'], 'handoffs/{handoff}/events' => ['handoff.transition', 'handoff']] as $path => [$operation, $parameter]) {
        Route::post('/'.$path, [QaController::class, 'decision'])->whereUuid($parameter)->defaults('qa_operation', $operation);
    }
    Route::post('/selections', [QaController::class, 'decision'])->defaults('qa_operation', 'selection.select');
    foreach (['verification', 'queue'] as $operation) {
        Route::get('/'.$operation, [QaController::class, 'read'])->defaults('qa_operation', $operation);
    }
    Route::get('/history/{kind}/{subject}', [QaController::class, 'read'])->whereUuid('subject')->defaults('qa_operation', 'history');
});

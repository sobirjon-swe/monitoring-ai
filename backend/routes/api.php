<?php

// All routes in this file inherit authentication. Domain permissions are added in T03–T05.

use App\Http\Controllers\IdentityController;
use App\Http\Controllers\IdentityMappingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProjectController;
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

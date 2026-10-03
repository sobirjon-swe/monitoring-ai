<?php

// All routes in this file inherit authentication. Domain permissions are added in T03–T05.

use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

foreach (['employees', 'departments'] as $resource) {
    Route::match(['GET', 'POST'], '/v1/'.$resource, [OrganizationController::class, 'handle'])->defaults('organization_resource', $resource);
    Route::patch('/v1/'.$resource.'/{id}', [OrganizationController::class, 'handle'])->whereUuid('id')->defaults('organization_resource', $resource);
}

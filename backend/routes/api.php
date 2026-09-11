<?php

use App\Http\Controllers\Api\V1\Admin\ReferenceDataController;
use App\Http\Controllers\Api\V1\Admin\RolePermissionController;
use App\Http\Controllers\Api\V1\Admin\ServicesPricingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\Hr\PersonnelController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'system' => 'YODAMEN',
        'backend' => 'Laravel',
        'database' => 'MySQL',
        'timezone' => config('app.timezone'),
    ]);
});

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/access-code', [AuthController::class, 'changeAccessCode']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bootstrap', BootstrapController::class);

        Route::prefix('admin')->group(function () {
            Route::get('/roles', [RolePermissionController::class, 'index'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');
            Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');

            Route::get('/references/categories', [ReferenceDataController::class, 'categories'])
                ->middleware('permission:references,VIEW');
            Route::get('/references', [ReferenceDataController::class, 'index'])
                ->middleware('permission:references,VIEW');
            Route::post('/references', [ReferenceDataController::class, 'store'])
                ->middleware('permission:references,CREATE');
            Route::put('/references/{referenceValue}', [ReferenceDataController::class, 'update'])
                ->middleware('permission:references,EDIT');
            Route::patch('/references/{referenceValue}/toggle', [ReferenceDataController::class, 'toggle'])
                ->middleware('permission:references,TOGGLE');

            Route::get('/services', [ServicesPricingController::class, 'index'])
                ->middleware('permission:services-pricing,VIEW');
            Route::get('/services/{serviceRecord}', [ServicesPricingController::class, 'show'])
                ->middleware('permission:services-pricing,VIEW');
            Route::post('/services', [ServicesPricingController::class, 'store'])
                ->middleware('permission:services-pricing,CREATE');
            Route::put('/services/{serviceRecord}', [ServicesPricingController::class, 'update'])
                ->middleware('permission:services-pricing,EDIT');
            Route::post('/services/{serviceRecord}/prices', [ServicesPricingController::class, 'changePrice'])
                ->middleware('permission:services-pricing,CHANGE_PRICE');
        });

        Route::prefix('hr')->group(function () {
            Route::get('/personnel', [PersonnelController::class, 'index'])
                ->middleware('permission:employees,VIEW');
            Route::post('/personnel', [PersonnelController::class, 'store'])
                ->middleware('permission:employees,CREATE');
            Route::put('/personnel/{personnel}', [PersonnelController::class, 'update'])
                ->middleware('permission:employees,EDIT');
        });
    });
});

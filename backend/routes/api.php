<?php

use App\Http\Controllers\Api\V1\Admin\ReferenceDataController;
use App\Http\Controllers\Api\V1\Admin\RolePermissionController;
use App\Http\Controllers\Api\V1\Admin\ServicesPricingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\Hr\PersonnelController;
use App\Http\Controllers\Api\V1\Operations\BookingController;
use App\Http\Controllers\Api\V1\Operations\CustomerController;
use App\Http\Controllers\Api\V1\Operations\QueueController;
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

        Route::prefix('operations')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])
                ->middleware('permission:customers,VIEW');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])
                ->middleware('permission:customers,VIEW');
            Route::post('/customers', [CustomerController::class, 'store'])
                ->middleware('permission:customers,CREATE');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])
                ->middleware('permission:customers,EDIT');
            Route::patch('/customers/{customer}/toggle', [CustomerController::class, 'toggle'])
                ->middleware('permission:customers,TOGGLE');

            Route::get('/bookings/bootstrap', [BookingController::class, 'bootstrap'])
                ->middleware('permission:bookings,VIEW');
            Route::get('/bookings', [BookingController::class, 'index'])
                ->middleware('permission:bookings,VIEW');
            Route::get('/bookings/{booking}', [BookingController::class, 'show'])
                ->middleware('permission:bookings,VIEW');
            Route::post('/bookings', [BookingController::class, 'store'])
                ->middleware('permission:bookings,CREATE');
            Route::put('/bookings/{booking}', [BookingController::class, 'update'])
                ->middleware('permission:bookings,EDIT');
            Route::post('/bookings/{booking}/rebook', [BookingController::class, 'rebook'])
                ->middleware('permission:bookings,EDIT');
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])
                ->middleware('permission:bookings,EDIT');

            Route::post('/bookings/{booking}/check-in', [QueueController::class, 'checkIn'])
                ->middleware(['permission:bookings,EDIT', 'permission:queue,CREATE']);

            Route::get('/queue/bootstrap', [QueueController::class, 'bootstrap'])
                ->middleware('permission:queue,VIEW');
            Route::get('/queue', [QueueController::class, 'index'])
                ->middleware('permission:queue,VIEW');
            Route::get('/queue/{queueEntry}', [QueueController::class, 'show'])
                ->middleware('permission:queue,VIEW');
            Route::post('/queue/walk-ins', [QueueController::class, 'createWalkIn'])
                ->middleware(['permission:queue,CREATE', 'permission:bookings,CREATE']);
            Route::post('/queue/{queueEntry}/cancel', [QueueController::class, 'cancel'])
                ->middleware('permission:queue,EDIT');
        });

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

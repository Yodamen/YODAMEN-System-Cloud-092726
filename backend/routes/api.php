<?php

use App\Http\Controllers\Api\V1\Admin\RolePermissionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
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
        });
    });
});

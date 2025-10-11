<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DealershipController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Открытие сессии (логин)
    Route::post(
        '/session',
        [SessionController::class, 'store']
    )->middleware('throttle:100,1440');

    // Регистрация пользовтеля (регистрация)
    Route::post(
        '/register',
        [AuthController::class, 'register']
    )->middleware('throttle:50,1440');

    // Закрытие сессии (логаут)
    Route::delete(
        '/session',
        [SessionController::class, 'destroy']
    )->middleware(['auth:sanctum', 'throttle:5,1440']);

    // Проверка работоспособности API
    Route::get('/up', function () {
        return response()->json(['success' => true], 200);
    })->middleware('throttle:100,1');

    Route::middleware([
            'auth:sanctum',
            'throttle:150,1'
        ])
        ->group(function () {
            // Users
            Route::get('/users', [UserApiController::class, 'index']);
            Route::get('/users/{id}', [UserApiController::class, 'show']);
            Route::get('/users/{id}/status', [UserApiController::class, 'status']);

            // Dealerships
            Route::get('/dealerships', [DealershipController::class, 'index']);
            Route::post('/dealerships', [DealershipController::class, 'store']);
            Route::get('/dealerships/{id}', [DealershipController::class, 'show']);
            Route::put('/dealerships/{id}', [DealershipController::class, 'update']);

            // Shifts
            Route::get('/shifts', [ShiftController::class, 'index']);
            Route::get('/shifts/current', [ShiftController::class, 'current']);
            Route::get('/shifts/statistics', [ShiftController::class, 'statistics']);
            Route::get('/shifts/{id}', [ShiftController::class, 'show']);

            // Tasks
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::get('/tasks/postponed', [TaskController::class, 'postponed']);
            Route::get('/tasks/{id}', [TaskController::class, 'show']);
            Route::put('/tasks/{id}', [TaskController::class, 'update']);

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);
        });
});

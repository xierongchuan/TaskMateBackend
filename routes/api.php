<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Открытие сессии (логин)
    Route::post(
        '/session',
        [SessionController::class, 'store']
    )->middleware('throttle:5,1440');

    // Регистрация пользовтеля (регистрация)
    Route::post(
        '/register',
        [AuthController::class, 'register']
    )->middleware('throttle:100,1440');

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
            'auth:sanctum',      // проверка токена Sanctum
            'throttle:150,1'     // лимит запросов
        ])
        ->group(function () {
            Route::get('/users', [UserApiController::class, 'index']);
            Route::get('/users/{id}', [UserApiController::class, 'show']);
            Route::get('/users/{id}/status', [UserApiController::class, 'status']);
        });
});

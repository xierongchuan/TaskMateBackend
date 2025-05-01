<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\UserController;


Route::prefix('v1')->group(function () {

    Route::prefix('session')->group(function () {
        Route::post('create', [SessionController::class, 'create']);
        Route::post('start', [SessionController::class, 'start']);
        Route::post('end', [SessionController::class, 'end'])->middleware('auth:sanctum');
    });

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/user', [UserController::class, 'self']);

    });
});

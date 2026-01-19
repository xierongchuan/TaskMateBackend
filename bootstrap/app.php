<?php

declare(strict_types=1);

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DuplicateTaskException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Глобальный middleware для проверки режима обслуживания
        $middleware->api(append: [
            \App\Http\Middleware\CheckMaintenanceMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DuplicateTaskException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'duplicate_task'
            ], 422);
        });

        $exceptions->render(function (AccessDeniedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'access_denied'
            ], 403);
        });
    })->create();

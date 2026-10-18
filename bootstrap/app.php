<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Integrations\IntegrationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (IntegrationException $exception, Request $request) {
            if ($request->is('api/integrations/*', 'api/recruitment/*')) {
                return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], 422);
            }
        });
    })->create();

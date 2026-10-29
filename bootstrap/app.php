<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Merge the two marked blocks into the bootstrap/app.php that `php artisan install:api` generated.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // >>> ADD
        $middleware->alias(['tenant' => ResolveTenant::class]);
        // <<< ADD
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // >>> ADD
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
        // <<< ADD
    })->create();

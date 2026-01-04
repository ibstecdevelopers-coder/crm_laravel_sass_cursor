<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\IPAddress;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register middleware alias
        // $middleware->alias([
        //     'restrict.ip' => IPAddress::class,
        // ]);

        // // Optionally apply to specific routes or groups
        // $middleware->web(append: [
        //     IPAddress::class, // Apply to all web routes
        // ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

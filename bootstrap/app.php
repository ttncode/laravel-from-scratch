<?php

use App\Http\Middleware\AuthenticationMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use Framework\Foundation\Application;
use Framework\Foundation\Configuration\Middleware;
use Framework\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RateLimitMiddleware::class);
        $middleware->append(AuthenticationMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();

<?php

namespace App\Providers;

use Framework\Routing\Router;
use Framework\Support\Facades\Route;
use Framework\Support\ServiceProvider;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Router::class, Router::class);
    }

    public function boot(): void
    {
        $routingConfig = $this->app->make('config.routing');

        if (isset($routingConfig['web']) && file_exists($routingConfig['web'])) {
            $router = $this->app->make(Router::class);

            // Initialize the Route facade with the router instance
            Route::setRouter($router);

            require $routingConfig['web'];
        }
    }
}

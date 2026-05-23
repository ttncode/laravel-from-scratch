<?php

namespace App\Providers;

use Framework\Foundation\Application;
use Framework\Support\ServiceProvider;
use Framework\View\View;

class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(View::class, function (Application $app) {
            return new View($app->resourcePath('views'));
        });
    }

    public function boot(): void
    {
        //
    }
}
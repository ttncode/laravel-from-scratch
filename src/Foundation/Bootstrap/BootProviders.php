<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class BootProviders
{
    /**
     * Bootstrap the given application.
     *
     * @param Application  $app
     * @return void
     */
    public function bootstrap(Application $app): void
    {
        $app->boot();
    }
}

<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class BootProviders
{
    public function bootstrap(Application $app): void
    {
        $app->boot();
    }
}

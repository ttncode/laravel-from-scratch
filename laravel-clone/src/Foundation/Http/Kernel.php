<?php

namespace Framework\Foundation\Http;

use Framework\Contracts\Http\Kernel as KernelContract;
use Framework\Foundation\Application;
use Framework\Http\Response;
use Framework\Routing\Router;

class Kernel implements KernelContract
{
    /**
     * The bootstrap classes for the application.
     *
     * @var string[]
     */
    protected $bootstrappers = [];

    public function __construct(
        protected Application $app,
        protected Router $router,
    ) {}

    /**
     * Bootstrap the application for HTTP requests.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers);
        }
    }

    /**
     * Handle the incoming HTTP request.
     *
     * @param \Framework\Http\Request $request
     * @return \Framework\Http\Response
     */
    public function handle($request): Response
    {
        try {
            $this->bootstrap();

            // Temporary: Load the routes manually here
            $routingConfig = $this->app->make('config.routing');

            if (isset($routingConfig['web']) && file_exists($routingConfig['web'])) {
                $router = $this->router;
                require $routingConfig['web'];
            }

            return $this->router->dispatch($request);

        } catch (\Throwable $th) {
            return new Response('Server error: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Framework\Http\Request  $request
     * @param  \Framework\Http\Response  $response
     * @return void
     */
    public function terminate($request, $response): void
    {
        // TODO: Implement closing database connections, writing session data.
    }

    /**
     * Get the Laravel application instance.
     *
     * @return \Framework\Foundation\Application
     */
    public function getApplication(): Application
    {
        return $this->app;
    }
}

<?php

namespace Framework\Foundation\Http;

use Framework\Contracts\Http\Kernel as KernelContract;
use Framework\Foundation\Application;
use Framework\Http\Response;

class Kernel implements KernelContract
{
    /**
     * The bootstrap classes for the application.
     *
     * @var string[]
     */
    protected $bootstrappers = [];

    public function __construct(protected Application $app) {}

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

            return new Response('Kernel is handling the request!', 200);
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

<?php

namespace Framework\Contracts\Http;

interface Kernel
{
    /**
     * Bootstrap the application for HTTP requests.
     *
     * @return void
     */
    public function bootstrap();

    /**
     * Handle an incoming HTTP request.
     *
     * @param  \Framework\Http\Request  $request
     * @return \Framework\Http\Response
     */
    public function handle($request);

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Framework\Http\Request  $request
     * @param  \Framework\Http\Response  $response
     * @return void
     */
    public function terminate($request, $response);

    /**
     * Get the Laravel application instance.
     *
     * @return \Framework\Foundation\Application
     */
    public function getApplication();
}

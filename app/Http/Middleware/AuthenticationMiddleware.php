<?php

namespace App\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;

class AuthenticationMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}

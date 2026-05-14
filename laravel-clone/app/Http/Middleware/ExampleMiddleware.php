<?php

namespace App\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;

class ExampleMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        
    }
}

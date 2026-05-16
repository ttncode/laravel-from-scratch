<?php

namespace Framework\Routing;

use Framework\Http\Request;
use Framework\Http\Response;

class Router
{
    /** @var Route[] */
    protected array $routes = [];

    /**
     * Register a new GET route with the router.
     *
     * @param string $uri
     * @param \Closure|array $action
     * @return void
     */
    public function get(string $uri, \Closure|array $action): void
    {
        $this->addRoute('GET', $uri, $action);
    }

    /**
     * Register a new POST route with the router.
     *
     * @param string $uri
     * @param \Closure|array $action
     * @return void
     */
    public function post(string $uri, \Closure|array $action): void
    {
        $this->addRoute('POST', $uri, $action);
    }

    /**
     * Add a route to the underlying route collection.
     *
     * @param string $method
     * @param string $uri
     * @param \Closure|array $action
     * @return void
     */
    protected function addRoute(string $method, string $uri, \Closure|array $action): void
    {
        $this->routes[] = new Route($method, $uri, $action);
    }

    /**
     * Dispatch the request to the application.
     *
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                // Execute the route's action
                $content = call_user_func($route->action);

                if ($content instanceof Response) {
                    return $content;
                }

                return new Response(is_string($content) ? $content : json_encode($content));
            }
        }

        return new Response('<h1 style="color: black;">404 Not Found!</h1>', 404);
    }
}

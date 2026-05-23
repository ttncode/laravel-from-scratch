<?php

namespace Framework\Support\Facades;

use Framework\Routing\Router;

class Route
{
    protected static ?Router $router = null;

    /**
     * Set the router instance for this facade.
     */
    public static function setRouter(Router $router): void
    {
        static::$router = $router;
    }

    /**
     * Get the underlying router instance.
     */
    public static function getRouter(): Router
    {
        if (static::$router === null) {
            throw new \RuntimeException('Router instance has not been set on the Route facade.');
        }
        return static::$router;
    }

    /**
     * Register a GET route.
     */
    public static function get(string $uri, \Closure|array $action): void
    {
        static::getRouter()->get($uri, $action);
    }

    /**
     * Register a POST route.
     */
    public static function post(string $uri, \Closure|array $action): void
    {
        static::getRouter()->post($uri, $action);
    }
}

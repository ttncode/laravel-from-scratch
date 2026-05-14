<?php

namespace Framework\Routing;

class Route
{
    public function __construct(
        public string $method,
        public string $uri,
        public \Closure|array $action,
    ) {}

    /**
     * Determine if the route matches a given request.
     *
     * @param string $method
     * @param string $path
     * @return boolean
     */
    public function matches(string $method, string $path): bool
    {
        return $this->method === $method && $this->uri === $path;
    }
}

<?php

use Framework\Container\Container;
use Framework\View\View;

if (! function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @param  string|null  $abstract
     * @return mixed
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        if (is_null($abstract)) {
            return Container::getInstance();
        }

        return Container::getInstance()->make($abstract, $parameters);
    }
}

if (! function_exists('view')) {
    /**
     * Render a view file with the given data.
     *
     * @param string $view The view name (e.g. 'home.index')
     * @param array $data
     * @return string
     */
    function view(string $view, array $data = []): string
    {
        return app(View::class)->render($view, $data);
    }
}

if (! function_exists('asset')) {
    /**
     * Generate an asset URL for the application.
     */
    function asset(string $path): string
    {
        return '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (! function_exists('env')) {
    /**
     * Get the value of an environment variable.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert "true"/"false" to boolean
        if (strtolower($value) === 'true') {
            return true;
        }
        if (strtolower($value) === 'false') {
            return false;
        }

        return $value;
    }
}

if (! function_exists('config')) {
    /**
     * Get the specified configuration value.
     *
     * @param array<string, mixed>|string $key
     * @param mixed $default
     * @return mixed
     */
    function config(array|string $key, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return app('config');
        }

        if (is_array($key)) {
            return app('config')->set($key);
        }

        return app('config')->get($key, $default);
    }
}

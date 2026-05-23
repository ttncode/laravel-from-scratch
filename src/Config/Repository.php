<?php

namespace Framework\Config;

use Framework\Contracts\Config\Repository as ConfigContract;

class Repository implements ConfigContract
{
    /**
     * All of the configuration items.
     *
     * @var array<string,mixed>
     */
    protected $items = [];

    /**
     * Create a new configuration repository.
     *
     * @param  array  $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Determine if the given configuration value exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function has($key): bool
    {
        $items = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (! isset($items[$segment])) {
                return false;
            }

            $items = $items[$segment];
        }

        return true;
    }

    /**
     * Set a given configuration value.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @return void
     */
    public function set($key, $value = null)
    {
        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $key => $value) {
            $this->items[$key] = $value;
        }
    }

    /**
     * Get the specified configuration value.
     *
     * @param  array|string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $items = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (! isset($items[$segment])) {
                return $default;
            }

            $items = $items[$segment];
        }

        return $items;
    }
}

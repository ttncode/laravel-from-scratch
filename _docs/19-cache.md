# Step 19: Cache

---

## 🚩 The Problem

Some operations are expensive: a complex database query, a remote API call, or a heavy computation. Running them on every request is wasteful:

```php
public function dashboard()
{
    // This query hits the DB on every single page load:
    $stats = DB::table('orders')
        ->selectRaw('COUNT(*) as total, SUM(amount) as revenue')
        ->where('created_at', '>=', now()->subDays(30))
        ->first();

    return view('dashboard', compact('stats'));
}
```

The naive "fix" is a global variable — which works for a single request but resets on every new request:

```php
static $stats = null; // Only lives for one request. Useless.
```

**Why is this a real problem?**

1. **Slow pages:** The same heavy query runs for every user on every visit.
2. **Database pressure:** Hundreds of concurrent users = hundreds of identical queries.
3. **No sharing:** Each request is isolated — one request cannot reuse what another computed.

---

## 💡 The Solution: Driver-based Cache with a Unified API

Laravel's cache layer provides a single API that works regardless of the underlying storage (file, Redis, database, Memcached). The `CacheManager` resolves the configured store; `Repository` wraps it with a convenient fluent interface.

```
Cache::remember('dashboard_stats', 3600, fn() => expensiveQuery())
    └── CacheManager::store()          ← resolves the default store
            └── Repository::remember() ← checks cache first
                    └── FileStore / RedisStore / DatabaseStore
```

```php
// Cache for 1 hour. Only runs the callback on cache miss.
$stats = Cache::remember('dashboard_stats', 3600, function () {
    return DB::table('orders')
        ->selectRaw('COUNT(*) as total, SUM(amount) as revenue')
        ->where('created_at', '>=', now()->subDays(30))
        ->first();
});
```

---

## 🏗 Implementation

### File: `Illuminate/Cache/CacheManager.php` (original, key parts)

```php
<?php

namespace Illuminate\Cache;

/**
 * @mixin \Illuminate\Cache\Repository
 */
class CacheManager implements FactoryContract
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The array of resolved cache stores.
     *
     * @var array
     */
    protected $stores = [];

    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected $customCreators = [];

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a cache store instance by name, wrapped in a repository.
     *
     * @param  string|null  $name
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function store($name = null)
    {
        $name = $name ?? $this->getDefaultDriver();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given store from config.
     */
    public function resolve($name)
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        return $this->build($config);
    }

    /**
     * Build a cache repository for the given config.
     * Routes to the correct createXxxDriver() method.
     */
    public function build(array $config)
    {
        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    // File-based cache — stores serialized values in the filesystem.
    protected function createFileDriver(array $config)
    {
        return $this->repository(
            new FileStore($this->app['files'], $config['path']),
            $config
        );
    }

    // Array driver — in-memory only, resets each request. Useful for testing.
    protected function createArrayDriver(array $config)
    {
        return $this->repository(new ArrayStore($config['serialize'] ?? false), $config);
    }

    // Database driver — stores cache in a `cache` table.
    protected function createDatabaseDriver(array $config)
    {
        $connection = $this->app['db']->connection($config['connection'] ?? null);

        $store = new DatabaseStore(
            $connection,
            $config['table'],
            $this->getPrefix($config),
        );

        return $this->repository($store, $config);
    }

    // Redis driver — fastest; requires Redis server.
    protected function createRedisDriver(array $config)
    {
        $redis = $this->app['redis'];
        $connection = $config['connection'] ?? 'default';

        $store = new RedisStore($redis, $this->getPrefix($config), $connection);

        return $this->repository($store, $config);
    }

    /**
     * Create a new cache repository with the given store implementation.
     * Repository wraps the Store and adds features like events and tagging.
     */
    public function repository(Store $store, array $config = [])
    {
        return tap(new Repository($store), function ($repository) use ($config) {
            if ($config['events'] ?? true) {
                $this->setEventDispatcher($repository);
            }
        });
    }

    public function getDefaultDriver()
    {
        return $this->app['config']['cache.default'] ?? 'null';
    }

    /**
     * Dynamically call the default store instance.
     * This is why Cache::get() works.
     */
    public function __call($method, $parameters)
    {
        return $this->store()->$method(...$parameters);
    }
}
```

### `Repository` — the fluent cache API

```php
<?php

namespace Illuminate\Cache;

class Repository implements CacheContract
{
    /**
     * The cache store implementation.
     *
     * @var \Illuminate\Contracts\Cache\Store
     */
    protected $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /**
     * Retrieve an item from the cache.
     */
    public function get($key, $default = null)
    {
        $value = $this->store->get($this->itemKey($key));

        if (is_null($value)) {
            return value($default);
        }

        return $value;
    }

    /**
     * Store an item in the cache.
     *
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl  Seconds or null = forever
     */
    public function put($key, $value, $ttl = null)
    {
        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        return $this->store->put($this->itemKey($key), $value, $seconds);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     * This is the most commonly used method.
     */
    public function remember($key, $ttl, Closure $callback)
    {
        $value = $this->get($key);

        if (! is_null($value)) {
            return $value;
        }

        $this->put($key, $value = $callback(), $ttl);

        return $value;
    }

    /**
     * Get an item from the cache, or execute and store forever.
     */
    public function rememberForever($key, Closure $callback)
    {
        $value = $this->get($key);

        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget($key)
    {
        return $this->store->forget($this->itemKey($key));
    }

    /**
     * Remove all items from the cache.
     */
    public function flush()
    {
        return $this->store->flush();
    }
}
```

### Cache configuration (`config/cache.php`)

```php
return [
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path'   => storage_path('framework/cache/data'),
        ],
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],
        'database' => [
            'driver'     => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table'      => env('DB_CACHE_TABLE', 'cache'),
        ],
        'redis' => [
            'driver'     => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'laravel_cache_'),
];
```

---

## ✅ Verify

```php
// routes/web.php
$router->get('/cache-test', function () use ($app) {
    $cache = $app['cache'];

    // Store something
    $cache->put('greeting', 'Hello, World!', 60);

    // Retrieve it
    $value = $cache->get('greeting');  // 'Hello, World!'

    // remember() — only runs the callback once
    $stats = $cache->remember('heavy_query', 3600, function () {
        return ['total' => 999, 'revenue' => 12345];
    });

    return json_encode(['value' => $value, 'stats' => $stats]);
});
```

---

## 📌 What We Built

| Element            | Purpose                                                                       |
| ------------------ | ----------------------------------------------------------------------------- |
| `CacheManager`     | Resolves named stores from config; `__call` forwards to default store         |
| `Repository`       | Unified API: `get`, `put`, `remember`, `forget`, `flush`                      |
| `Store` (contract) | Interface all drivers implement — swappable without changing application code |
| `FileStore`        | Serializes values to files in `storage/framework/cache/`                      |
| `ArrayStore`       | In-memory only — resets each request; perfect for tests                       |
| `DatabaseStore`    | Uses a `cache` table — no Redis needed                                        |
| `RedisStore`       | Fastest; supports atomic operations and tags                                  |

---

## ⚠️ Simplifications vs Laravel

| Laravel                      | Our Implementation | Reason                                                                               |
| ---------------------------- | ------------------ | ------------------------------------------------------------------------------------ |
| `Cache::tags()`              | Present in source  | Groups related keys so `Cache::tags('users')->flush()` only clears user keys         |
| `Cache::lock()`              | Present in source  | Atomic distributed locks — prevents race conditions in multiple-server setups        |
| `CacheServiceProvider`       | Not built          | Registers `'cache'`, `'cache.store'` in the container and sets up the `Cache` facade |
| `remember()` with `null` TTL | Present in source  | `null` means cache forever                                                           |
| Memoized store               | Present in source  | Caches within a single request without TTL expiry                                    |

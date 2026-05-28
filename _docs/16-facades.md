# Step 16: Facades

---

## 🚩 The Problem

After Steps 13–15, every service lives in the container — but accessing it requires passing the container around or injecting it everywhere:

```php
// Option 1: inject the full container into every class (bad)
class UserController
{
    public function __construct(private Application $app) {}

    public function index()
    {
        return $this->app['db']->table('users')->get();
    }
}

// Option 2: pull from a global variable (worse)
global $app;
$users = $app['db']->table('users')->get();
```

**Why is this bad?**

1. **Verbose:** Every class must declare a constructor dependency just to reach a service.
2. **Hard to read in tests:** `$this->app['cache']->put(...)` is noisier than `Cache::put(...)`.
3. **No IDE autocomplete:** `$this->app['db']` returns `mixed`; the IDE cannot infer types.

---

## 💡 The Solution: Static Proxy via `__callStatic`

A **Facade** is a class with one job: when you call a static method on it, forward the call to a concrete instance resolved from the container.

```php
// This call:
DB::table('users')->get();

// Is exactly equivalent to:
app('db')->table('users')->get();
```

The magic is `__callStatic` + a single abstract method `getFacadeAccessor()`:

```
DB::table('users')
    └── Facade::__callStatic('table', ['users'])
            └── static::getFacadeRoot()
                    └── static::resolveFacadeInstance('db')
                            └── static::$app['db']   ← container lookup
                                    └── DatabaseManager::table('users')
```

Each concrete facade defines only which container key it resolves:

```php
class DB extends Facade
{
    protected static function getFacadeAccessor() { return 'db'; }
}
```

---

## 🏗 Implementation

### File: `Illuminate/Support/Facades/Facade.php` (original, key parts)

```php
<?php

namespace Illuminate\Support\Facades;

abstract class Facade
{
    /**
     * The application instance being facaded.
     *
     * @var \Illuminate\Contracts\Foundation\Application|null
     */
    protected static $app;

    /**
     * The resolved object instances.
     *
     * @var array
     */
    protected static $resolvedInstance;

    /**
     * Indicates if the resolved instance should be cached.
     *
     * @var bool
     */
    protected static $cached = true;

    /**
     * Get the root object behind the facade.
     *
     * @return mixed
     */
    public static function getFacadeRoot()
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * Get the registered name of the component.
     * Subclasses MUST override this.
     *
     * @throws \RuntimeException
     */
    protected static function getFacadeAccessor()
    {
        throw new RuntimeException('Facade does not implement getFacadeAccessor method.');
    }

    /**
     * Resolve the facade root instance from the container.
     *
     * @param  string  $name
     * @return mixed
     */
    protected static function resolveFacadeInstance($name)
    {
        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }

        if (static::$app) {
            if (static::$cached) {
                return static::$resolvedInstance[$name] = static::$app[$name];
            }

            return static::$app[$name];
        }
    }

    /**
     * Hotswap the underlying instance behind the facade.
     * Used in tests: DB::swap($mockConnection).
     *
     * @param  mixed  $instance
     * @return void
     */
    public static function swap($instance)
    {
        static::$resolvedInstance[static::getFacadeAccessor()] = $instance;

        if (isset(static::$app)) {
            static::$app->instance(static::getFacadeAccessor(), $instance);
        }
    }

    /**
     * Clear all of the resolved instances.
     *
     * @return void
     */
    public static function clearResolvedInstances()
    {
        static::$resolvedInstance = [];
    }

    /**
     * Set the application instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application|null  $app
     * @return void
     */
    public static function setFacadeApplication($app)
    {
        static::$app = $app;
    }

    /**
     * Handle dynamic, static calls to the object.
     * This is the heart of the Facade pattern.
     *
     * @throws \RuntimeException
     */
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->$method(...$args);
    }
}
```

### Concrete Facade: `DB`

```php
<?php

namespace Illuminate\Support\Facades;

class DB extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'db';
    }
}
```

### How facades are registered (from `Foundation\Application`)

```php
// In bootstrap/app.php or Application::registerCoreAliases():
Facade::setFacadeApplication($app);

// Class aliases — so you can write DB:: instead of Illuminate\Support\Facades\DB::
$aliases = [
    'DB'    => \Illuminate\Support\Facades\DB::class,
    'Cache' => \Illuminate\Support\Facades\Cache::class,
    'Event' => \Illuminate\Support\Facades\Event::class,
    'Route' => \Illuminate\Support\Facades\Route::class,
    'Auth'  => \Illuminate\Support\Facades\Auth::class,
];
```

### All default facades (from `Facade::defaultAliases()`)

```php
public static function defaultAliases()
{
    return new Collection([
        'App'       => App::class,
        'Auth'      => Auth::class,
        'Cache'     => Cache::class,
        'Config'    => Config::class,
        'DB'        => DB::class,
        'Event'     => Event::class,
        'Gate'      => Gate::class,
        'Hash'      => Hash::class,
        'Log'       => Log::class,
        'Queue'     => Queue::class,
        'Route'     => Route::class,
        'Schema'    => Schema::class,
        'Session'   => Session::class,
        'Storage'   => Storage::class,
        'Validator' => Validator::class,
        'View'      => View::class,
    ]);
}
```

---

## ✅ Verify

In `bootstrap/app.php`, add:

```php
use Illuminate\Support\Facades\Facade;

Facade::setFacadeApplication($app);
```

Then in a route:

```php
use Illuminate\Support\Facades\DB;

$router->get('/facade-test', function () {
    // Exactly the same as $app['db']->table('users')->get()
    $users = DB::table('users')->get();

    return json_encode($users);
});
```

---

## 📌 What We Built

| Element                          | Purpose                                                                        |
| -------------------------------- | ------------------------------------------------------------------------------ |
| `Facade`                         | Abstract base — `__callStatic` resolves the real instance from `static::$app`  |
| `getFacadeAccessor()`            | The only method subclasses must implement — returns the container key          |
| `static::$resolvedInstance`      | Cache of resolved objects — avoids repeated container lookups                  |
| `Facade::swap()`                 | Replaces the resolved instance — essential for testing without a real DB/cache |
| `Facade::setFacadeApplication()` | Wires the container into every facade at bootstrap                             |

---

## ⚠️ Simplifications vs Laravel

| Laravel                                                   | Our Implementation          | Reason                                                                   |
| --------------------------------------------------------- | --------------------------- | ------------------------------------------------------------------------ |
| `Facade::spy()` / `shouldReceive()`                       | Present in source           | Uses Mockery to replace the facade for testing                           |
| `$cached = false`                                         | Present in source           | Some facades (`Request`) must not cache — request changes per HTTP cycle |
| PHPDoc `@method` annotations                              | Present in original facades | Give IDEs method hints since `__callStatic` is otherwise opaque          |
| Real-time facades (`Facades\App\Services\PaymentGateway`) | Not built                   | Generates facade proxies on the fly for any class                        |

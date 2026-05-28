# Step 17: Authentication

---

## 🚩 The Problem

You have users in the database from Steps 13–14. Now you need to know _who is making each request_ and _whether they are allowed to_. The naive approach is to check the session in every controller:

```php
public function dashboard(Request $request)
{
    // Must repeat this guard in every protected controller:
    if (! isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }

    $userId = $_SESSION['user_id'];
    $user = DB::table('users')->where('id', $userId)->first();

    // ...render dashboard
}

public function settings(Request $request)
{
    // Copied and pasted again:
    if (! isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    // ...
}
```

**Why is this bad?**

1. **Repetitive:** Every protected route must repeat the session check.
2. **Inconsistent:** One forgot check = security hole.
3. **Coupled to sessions:** Switching to API token auth requires rewriting all controllers.
4. **No abstraction:** "Who is the current user?" has no single canonical answer.

---

## 💡 The Solution: Guards + Providers via `AuthManager`

Laravel decouples _how_ authentication works (the **Guard**) from _where_ users come from (the **UserProvider**):

```
Auth::user()
    └── AuthManager::guard()           ← resolves the configured guard
            └── SessionGuard           ← reads session, returns Authenticatable
                    └── EloquentUserProvider  ← fetches User model from DB
                            └── User::find($id)
```

- **`AuthManager`** — manages named guards; delegates to the configured default.
- **`Guard`** — knows how to identify the current user (`session`, `token`, `request`).
- **`UserProvider`** — knows how to fetch a user by credentials from any source.

Configuration in `config/auth.php`:

```php
'defaults' => ['guard' => 'web'],

'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'api' => ['driver' => 'token',   'provider' => 'users'],
],

'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
],
```

---

## 🏗 Implementation

### File: `Illuminate/Auth/AuthManager.php` (original, key parts)

```php
<?php

namespace Illuminate\Auth;

class AuthManager implements FactoryContract
{
    use CreatesUserProviders;

    protected $app;
    protected $customCreators = [];

    /**
     * The array of created "drivers" (guards).
     *
     * @var array
     */
    protected $guards = [];

    /**
     * The user resolver shared by various services.
     * Determines the default user for Gate, Request, etc.
     *
     * @var \Closure
     */
    protected $userResolver;

    public function __construct($app)
    {
        $this->app = $app;

        $this->userResolver = fn ($guard = null) => $this->guard($guard)->user();
    }

    /**
     * Attempt to get the guard from the local cache.
     *
     * @param  string|null  $name
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    public function guard($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->guards[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given guard from config.
     */
    protected function resolve($name)
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Auth guard [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($name, $config);
        }

        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($name, $config);
        }

        throw new InvalidArgumentException(
            "Auth driver [{$config['driver']}] for guard [{$name}] is not defined."
        );
    }

    /**
     * Create a session based authentication guard.
     */
    public function createSessionDriver($name, $config)
    {
        $guard = new SessionGuard(
            $name,
            $this->createUserProvider($config['provider'] ?? null),
            $this->app['session.store'],
        );

        $guard->setCookieJar($this->app['cookie']);
        $guard->setDispatcher($this->app['events']);
        $guard->setRequest($this->app->refresh('request', $guard, 'setRequest'));

        return $guard;
    }

    /**
     * Create a token based authentication guard.
     */
    public function createTokenDriver($name, $config)
    {
        $guard = new TokenGuard(
            $this->createUserProvider($config['provider'] ?? null),
            $this->app['request'],
            $config['input_key'] ?? 'api_token',
            $config['storage_key'] ?? 'api_token',
            $config['hash'] ?? false
        );

        $this->app->refresh('request', $guard, 'setRequest');

        return $guard;
    }

    protected function getConfig($name)
    {
        return $this->app['config']["auth.guards.{$name}"];
    }

    public function getDefaultDriver()
    {
        return $this->app['config']['auth.defaults.guard'];
    }

    /**
     * Dynamically call the default driver instance.
     * This is why Auth::user() works.
     */
    public function __call($method, $parameters)
    {
        return $this->guard()->{$method}(...$parameters);
    }
}
```

### `SessionGuard` — how login and `user()` work

```php
<?php

namespace Illuminate\Auth;

class SessionGuard implements StatefulGuard
{
    use GuardHelpers;

    protected $name;
    protected $provider;    // UserProvider — fetches users from DB
    protected $session;     // Session store — reads/writes user ID
    protected $request;     // Current HTTP request

    // The currently authenticated user (cached for request lifetime).
    protected $user;

    public function __construct($name, UserProvider $provider, Session $session)
    {
        $this->name = $name;
        $this->provider = $provider;
        $this->session = $session;
    }

    /**
     * Get the currently authenticated user.
     * Reads user ID from session, then fetches the model.
     */
    public function user()
    {
        if ($this->loggedOut) {
            return;
        }

        // Return cached user for this request.
        if (! is_null($this->user)) {
            return $this->user;
        }

        $id = $this->session->get($this->getName());

        if (! is_null($id) && $this->user = $this->provider->retrieveById($id)) {
            $this->fireAuthenticatedEvent($this->user);
        }

        return $this->user;
    }

    /**
     * Attempt to authenticate using the given credentials.
     * Used by: Auth::attempt(['email' => ..., 'password' => ...])
     */
    public function attempt(array $credentials = [], $remember = false)
    {
        $this->fireAttemptEvent($credentials, $remember);

        $this->lastAttempted = $user = $this->provider->retrieveByCredentials($credentials);

        // validateCredentials checks the hashed password.
        if ($this->hasValidCredentials($user, $credentials)) {
            $this->login($user, $remember);

            return true;
        }

        $this->fireFailedEvent($user, $credentials);

        return false;
    }

    /**
     * Log a user into the application.
     * Stores the user ID in the session.
     */
    public function login(AuthenticatableContract $user, $remember = false)
    {
        $this->updateSession($user->getAuthIdentifier());

        $this->setUser($user);
    }

    protected function updateSession($id)
    {
        $this->session->put($this->getName(), $id);

        $this->session->migrate(true);
    }

    /**
     * Log the user out of the application.
     */
    public function logout()
    {
        $user = $this->user();

        $this->clearUserDataFromStorage();

        $this->user = null;
        $this->loggedOut = true;
    }

    // The session key used to store the user ID.
    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(static::class);
    }
}
```

### `EloquentUserProvider` — fetches users from the database

```php
<?php

namespace Illuminate\Auth;

class EloquentUserProvider implements UserProvider
{
    protected $hasher;  // Hash facade for password verification
    protected $model;   // The Eloquent model class

    /**
     * Retrieve a user by their unique identifier (ID from session).
     */
    public function retrieveById($identifier)
    {
        $model = $this->createModel();

        return $model->newQuery()
            ->where($model->getAuthIdentifierName(), $identifier)
            ->first();
    }

    /**
     * Retrieve a user by the given credentials (e.g. email + password).
     */
    public function retrieveByCredentials(array $credentials)
    {
        $query = $this->createModel()->newQuery();

        foreach ($credentials as $key => $value) {
            if (! str_contains($key, 'password')) {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }

    /**
     * Validate a user against the given credentials.
     */
    public function validateCredentials(UserContract $user, array $credentials)
    {
        return $this->hasher->check(
            $credentials['password'],
            $user->getAuthPassword()
        );
    }

    public function createModel()
    {
        $class = '\\'.ltrim($this->model, '\\');

        return new $class;
    }
}
```

---

## ✅ Verify

```php
// routes/web.php
$router->post('/login', function (Request $request) use ($app) {
    $credentials = [
        'email'    => $request->input('email'),
        'password' => $request->input('password'),
    ];

    if (Auth::attempt($credentials)) {
        return new Response('Logged in as: '.Auth::user()->name);
    }

    return new Response('Invalid credentials', 401);
});

$router->get('/me', function () {
    if (! Auth::check()) {
        return new Response('Unauthenticated', 401);
    }

    return json_encode(Auth::user());
});
```

---

## 📌 What We Built

| Element                | Purpose                                                                   |
| ---------------------- | ------------------------------------------------------------------------- |
| `AuthManager`          | Resolves named guards from config; `__call` forwards to the default guard |
| `SessionGuard`         | Reads/writes user ID to session; validates credentials via `UserProvider` |
| `TokenGuard`           | Reads API token from request header/query string                          |
| `EloquentUserProvider` | Fetches `User` model; compares hashed password                            |
| `DatabaseUserProvider` | Alternative provider using raw `DB::table()` instead of Eloquent          |
| `Auth` Facade          | `Auth::user()`, `Auth::attempt()`, `Auth::check()`, `Auth::logout()`      |

---

## ⚠️ Simplifications vs Laravel

| Laravel                     | Our Implementation                  | Reason                                                                       |
| --------------------------- | ----------------------------------- | ---------------------------------------------------------------------------- |
| `remember_me` cookie        | Present in source                   | Persists auth across sessions via a long-lived signed cookie                 |
| Password rehashing on login | Present in source (`rehashOnLogin`) | Re-hashes with current algorithm if the stored hash is outdated              |
| `Authenticate` middleware   | Not built                           | Rejects unauthenticated requests before they reach the controller            |
| `Auth::routes()`            | Not built                           | Generates login/register/password-reset routes automatically                 |
| Sanctum / Passport          | Not built                           | Token-based auth for SPAs/APIs — builds on the same Guard/Provider contracts |

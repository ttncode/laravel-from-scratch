# Step 18: Events & Listeners

---

## 🚩 The Problem

After a user registers, you need to send a welcome email, log the event, and award a signup bonus. The naive approach puts all of this directly in the controller:

```php
public function register(Request $request)
{
    $user = User::create([
        'name'  => $request->input('name'),
        'email' => $request->input('email'),
    ]);

    // Everything coupled in one place:
    Mail::send('welcome', $user);
    Log::info('New user registered: '.$user->email);
    CreditService::awardBonus($user, 100);

    return new Response('Registered!');
}
```

**Why is this bad?**

1. **Tight coupling:** The controller knows about Mail, Log, and CreditService. Adding a 4th action means editing the controller.
2. **Hard to test:** You cannot test registration without triggering emails and credits.
3. **No reuse:** If user creation also happens during an import, you must duplicate these calls.
4. **Order dependency:** All actions block the HTTP response, even if they are slow.

---

## 💡 The Solution: Event Dispatcher (Publish-Subscribe)

The **Dispatcher** decouples the thing that _triggers_ an action from the things that _respond_ to it:

```php
// Controller only knows about "something happened":
event(new UserRegistered($user));

// Listeners are registered elsewhere — each in its own class:
Event::listen(UserRegistered::class, SendWelcomeEmail::class);
Event::listen(UserRegistered::class, LogRegistration::class);
Event::listen(UserRegistered::class, AwardSignupBonus::class);
```

Adding a 4th action means registering a 4th listener — the controller is never touched.

```
Event::dispatch(UserRegistered $event)
    └── Dispatcher::dispatch()
            └── getListeners('App\Events\UserRegistered')
                    └── makeListener(SendWelcomeEmail::class)
                            └── container->make(SendWelcomeEmail)
                                    └── SendWelcomeEmail::handle($event)
                    └── makeListener(LogRegistration::class) ...
```

---

## 🏗 Implementation

### File: `Illuminate/Events/Dispatcher.php` (original, key parts)

```php
<?php

namespace Illuminate\Events;

class Dispatcher implements DispatcherContract
{
    /**
     * The IoC container instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The registered event listeners.
     *
     * @var array<string, callable|array|class-string|null>
     */
    protected $listeners = [];

    /**
     * The wildcard listeners.
     *
     * @var array<string, \Closure|string>
     */
    protected $wildcards = [];

    public function __construct(?ContainerContract $container = null)
    {
        $this->container = $container ?: new Container;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  callable|array|class-string|string  $events
     * @param  callable|array|class-string|null  $listener
     * @return void
     */
    public function listen($events, $listener = null)
    {
        if ($events instanceof Closure) {
            // Auto-detect event type from the Closure's first parameter.
            return (new Collection($this->firstClosureParameterTypes($events)))
                ->each(function ($event) use ($events) {
                    $this->listen($event, $events);
                });
        }

        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][] = $listener;
            }
        }
    }

    /**
     * Fire an event and call the listeners.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt  Stop after the first non-null listener response.
     * @return array|null
     */
    public function dispatch($event, $payload = [], $halt = false)
    {
        // When $event is an object, use its class name as the event name.
        [$isEventObject, $parsedEvent, $parsedPayload] = [
            is_object($event),
            ...$this->parseEventAndPayload($event, $payload),
        ];

        return $this->invokeListeners($parsedEvent, $parsedPayload, $halt);
    }

    protected function invokeListeners($event, $payload, $halt = false)
    {
        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

            // If halting, return on the first non-null response.
            if ($halt && ! is_null($response)) {
                return $response;
            }

            // Returning false from a listener stops propagation.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Parse the given event and payload.
     *
     * @return array{string, array}
     */
    protected function parseEventAndPayload($event, $payload)
    {
        if (is_object($event)) {
            [$payload, $event] = [[$event], get_class($event)];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListeners($eventName)
    {
        $listeners = array_merge(
            $this->prepareListeners($eventName),
            $this->wildcards[$eventName] ?? $this->getWildcardListeners($eventName)
        );

        return class_exists($eventName, false)
            ? $this->addInterfaceListeners($eventName, $listeners)
            : $listeners;
    }

    /**
     * Register an event listener with the dispatcher.
     * Turns a class name string into a callable Closure.
     */
    public function makeListener($listener, $wildcard = false)
    {
        if (is_string($listener)) {
            return $this->createClassListener($listener, $wildcard);
        }

        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            }

            return $listener(...array_values($payload));
        };
    }

    /**
     * Create a class based listener using the IoC container.
     * If the listener implements ShouldQueue, it is dispatched to the queue.
     */
    public function createClassListener($listener, $wildcard = false)
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            $callable = $this->createClassCallable($listener);

            return $callable(...array_values($payload));
        };
    }

    protected function createClassCallable($listener)
    {
        [$class, $method] = is_array($listener)
            ? $listener
            : $this->parseClassCallable($listener);

        if (! method_exists($class, $method)) {
            $method = '__invoke';
        }

        // If the listener implements ShouldQueue, push to queue instead of running now.
        if ($this->handlerShouldBeQueued($class)) {
            return $this->createQueuedHandlerCallable($class, $method);
        }

        $listener = $this->container->make($class);

        return [$listener, $method];
    }

    // Parses "SendWelcomeEmail" → ['SendWelcomeEmail', 'handle']
    protected function parseClassCallable($listener)
    {
        return Str::parseCallback($listener, 'handle');
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget($event)
    {
        if (str_contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event]);
        }
    }
}
```

### Defining an Event class

```php
<?php

namespace App\Events;

class UserRegistered
{
    public function __construct(
        public readonly User $user
    ) {}
}
```

### Defining a Listener class

```php
<?php

namespace App\Listeners;

use App\Events\UserRegistered;

class SendWelcomeEmail
{
    /**
     * Handle the event.
     * The Dispatcher resolves this class via the container.
     */
    public function handle(UserRegistered $event): void
    {
        // $event->user is the registered User model
        Mail::to($event->user->email)->send(new WelcomeMail($event->user));
    }
}
```

### Queued Listener (implements `ShouldQueue`)

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class AwardSignupBonus implements ShouldQueue
{
    // The Dispatcher detects ShouldQueue and pushes to the queue instead of running inline.
    public function handle(UserRegistered $event): void
    {
        CreditService::award($event->user, 100);
    }
}
```

### Registering listeners (in a ServiceProvider)

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $events = $this->app['events'];

        $events->listen(
            \App\Events\UserRegistered::class,
            \App\Listeners\SendWelcomeEmail::class,
        );

        $events->listen(
            \App\Events\UserRegistered::class,
            \App\Listeners\AwardSignupBonus::class,
        );
    }
}
```

---

## ✅ Verify

```php
// routes/web.php
$router->post('/register', function (Request $request) use ($app) {
    $user = User::create([
        'name'  => $request->input('name'),
        'email' => $request->input('email'),
    ]);

    // Fire the event — all listeners run automatically.
    $app['events']->dispatch(new \App\Events\UserRegistered($user));

    return new Response('Registered!');
});
```

Add a simple logging listener and confirm it runs:

```bash
tail -f storage/logs/laravel.log
# Should see: "New user registered: alice@example.com"
```

---

## 📌 What We Built

| Element                         | Purpose                                                                         |
| ------------------------------- | ------------------------------------------------------------------------------- |
| `Dispatcher`                    | Maintains `$listeners` map; fires all registered listeners for an event         |
| `listen($event, $listener)`     | Registers a listener — accepts class name, Closure, or `[Class, 'method']`      |
| `dispatch($event)`              | Fires the event; if `$event` is an object, its class becomes the event name     |
| `makeListener()`                | Wraps class name strings into callables resolved via the container              |
| `ShouldQueue`                   | Interface that signals the Dispatcher to push the listener to the queue instead |
| Wildcard listeners (`'user.*'`) | Match multiple event names with a single listener registration                  |

---

## ⚠️ Simplifications vs Laravel

| Laravel                                     | Our Implementation                    | Reason                                                                             |
| ------------------------------------------- | ------------------------------------- | ---------------------------------------------------------------------------------- |
| `EventServiceProvider` with `$listen` array | Not built                             | Reads `$listen = [Event::class => [Listener::class]]` and auto-registers           |
| Model events (`creating`, `saved`)          | Fired by `HasEvents` trait on `Model` | Eloquent calls `$this->fireModelEvent('saved')` which hits the Dispatcher          |
| `Event::until()`                            | Present in source                     | Fires listeners and stops at the first non-null response                           |
| `ShouldDispatchAfterCommit`                 | Present in source                     | Delays dispatch until the current DB transaction commits                           |
| `Dispatcher::subscribe()`                   | Present in source                     | Registers an entire subscriber class that declares its own events in `subscribe()` |

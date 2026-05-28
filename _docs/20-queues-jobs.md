# Step 20: Queues & Jobs

---

## 🚩 The Problem

Some tasks are too slow to do during an HTTP request. Sending an email, resizing an image, calling a third-party API, or generating a PDF can take seconds — but a user expects a response in milliseconds:

```php
public function register(Request $request)
{
    $user = User::create([...]);

    // This blocks the HTTP response for 2–5 seconds:
    Mail::send('welcome', $user);     // SMTP round-trip
    Thumbnail::generate($user->avatar); // CPU-heavy
    SlackNotifier::notify($user);      // External API

    return new Response('Registered!'); // Finally returned — too late
}
```

**Why is this bad?**

1. **Slow responses:** Users wait for every side-effect to complete before seeing any feedback.
2. **Fragile:** If the mail server is down, the _entire registration_ fails.
3. **No retry:** A failed email silently disappears with no mechanism to retry.
4. **No concurrency:** One slow job blocks the next request in the same process.

---

## 💡 The Solution: Serialize, Queue, and Work

Laravel breaks the problem into three roles:

```
HTTP Request ──► dispatch(SendWelcomeEmail::class) ──► Queue (database/Redis)
                                                              │
                                    Worker process ◄──────────┘
                                        └── deserialize Job
                                        └── Job::handle()
                                        └── mark complete / retry on failure
```

- **Job** — a plain PHP class with a `handle()` method. Serialized to JSON.
- **Queue** — durable storage (database table, Redis, SQS, Beanstalkd). Survives restarts.
- **Worker** — a long-running process that pulls jobs from the queue and calls `handle()`.

```php
// Controller dispatches — returns immediately:
dispatch(new SendWelcomeEmail($user));

// Job class:
class SendWelcomeEmail implements ShouldQueue
{
    public function __construct(public User $user) {}

    public function handle(): void
    {
        Mail::to($this->user->email)->send(new WelcomeMail($this->user));
    }
}
```

---

## 🏗 Implementation

### How `Queue::push()` serializes a Job

```php
<?php

namespace Illuminate\Queue;

abstract class Queue
{
    /**
     * The IoC container instance.
     *
     * @var \Illuminate\Container\Container
     */
    protected $container;

    protected $connectionName;

    /**
     * Push a new job onto the queue.
     */
    public function pushOn($queue, $job, $data = '')
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn($queue, $delay, $job, $data = '')
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Create a payload string from the given job and data.
     * The payload is a JSON string stored in the queue.
     */
    protected function createPayload($job, $queue, $data = '', $delay = null)
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $value = $this->createPayloadArray($job, $queue, $data);

        $payload = json_encode($value, \JSON_UNESCAPED_UNICODE);

        return $payload;
    }

    /**
     * Build the payload array for an object-based job.
     * The job class is serialized so the Worker can reconstruct it.
     */
    protected function createObjectPayload($job, $queue)
    {
        $payload = $this->withCreatePayloadHooks($queue, [
            'uuid'        => (string) Str::uuid(),
            'displayName' => $this->getDisplayName($job),
            'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries'    => $this->getJobTries($job),
            'timeout'     => null,
            'data'        => [
                'commandName' => $job,
                'command'     => $job,
            ],
        ]);

        // serialize() turns the Job object into a string — stored in the queue.
        $command = serialize(clone $job);

        return array_merge($payload, [
            'data' => array_merge($payload['data'], [
                'commandName' => get_class($job),
                'command'     => $command,
            ]),
        ]);
    }

    protected function getDisplayName($job)
    {
        return method_exists($job, 'displayName')
            ? $job->displayName()
            : get_class($job);
    }
}
```

### `QueueManager` — resolves queue connections

```php
<?php

namespace Illuminate\Queue;

class QueueManager implements FactoryContract
{
    protected $app;
    protected $connectors = [];

    /**
     * Resolve a queue connection instance.
     * Similar pattern to DatabaseManager and CacheManager.
     */
    public function connection($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name);
            $this->connections[$name]->setContainer($this->app);
        }

        return $this->connections[$name];
    }

    protected function resolve($name)
    {
        $config = $this->getConfig($name);

        return $this->getConnector($config['driver'])
            ->connect($config)
            ->setConnectionName($name);
    }

    public function getDefaultDriver()
    {
        return $this->app['config']['queue.default'];
    }

    // __call forwards to the default connection (same pattern as DB, Cache).
    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}
```

### A complete Job class

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    // Properties are serialized into the queue payload.
    // SerializesModels stores only the model ID — not the whole object.
    public function __construct(
        public User $user
    ) {}

    /**
     * Execute the job.
     * Called by the Worker when the job is pulled from the queue.
     */
    public function handle(): void
    {
        Mail::to($this->user->email)->send(new WelcomeMail($this->user));
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendWelcomeEmail failed for user '.$this->user->id.': '.$exception->getMessage());
    }
}
```

### Queue configuration (`config/queue.php`)

```php
return [
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        // 'sync' runs jobs immediately in the same request (no Worker needed).
        'sync' => ['driver' => 'sync'],

        // 'database' stores jobs in the `jobs` table.
        'database' => [
            'driver'     => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table'      => env('DB_QUEUE_TABLE', 'jobs'),
            'queue'      => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
        ],

        // 'redis' is the recommended production driver.
        'redis' => [
            'driver'      => 'redis',
            'connection'  => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'       => env('REDIS_QUEUE', '{default}'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
        ],
    ],

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table'    => 'failed_jobs',
    ],
];
```

---

## ✅ Verify

```bash
# Set QUEUE_CONNECTION=database in .env, then:

# Create the jobs table:
php artisan queue:table
php artisan migrate

# Dispatch a job:
php artisan tinker
>>> dispatch(new App\Jobs\SendWelcomeEmail(App\Models\User::find(1)));

# Inspect the jobs table — the job payload should be there:
# SELECT * FROM jobs;

# Start the worker — it will pull and process the job:
php artisan queue:work

# The worker output should show:
# [2024-01-01 00:00:00][1] Processing: App\Jobs\SendWelcomeEmail
# [2024-01-01 00:00:00][1] Processed:  App\Jobs\SendWelcomeEmail
```

---

## 📌 What We Built

| Element               | Purpose                                                                  |
| --------------------- | ------------------------------------------------------------------------ |
| `Queue` (abstract)    | Base class all drivers extend; builds JSON payload via `createPayload()` |
| `QueueManager`        | Resolves named queue connections from config                             |
| `ShouldQueue`         | Marker interface — signals `dispatch()` to serialize the job             |
| `SerializesModels`    | Stores only model ID in payload; re-fetches the model when the job runs  |
| `InteractsWithQueue`  | Adds `$this->release()`, `$this->fail()`, `$this->attempts()` to a job   |
| Worker (`queue:work`) | Long-running process that pops jobs and calls `handle()`                 |
| `failed_jobs` table   | Stores jobs that exhausted all retries for manual review                 |

---

## ⚠️ Simplifications vs Laravel

| Laravel                          | Our Implementation       | Reason                                                                     |
| -------------------------------- | ------------------------ | -------------------------------------------------------------------------- |
| `$tries`, `$timeout`, `$backoff` | Present in source        | Controls retry count, job timeout, and exponential backoff between retries |
| `$this->release()`               | Via `InteractsWithQueue` | Puts the job back on the queue with an optional delay                      |
| Job chaining (`->chain()`)       | Not built                | Runs a sequence of jobs where each waits for the previous to succeed       |
| Job batches (`Bus::batch()`)     | Not built                | Runs many jobs in parallel and fires a callback when all complete          |
| `ShouldBeUnique`                 | Present in source        | Prevents duplicate jobs from being pushed to the queue                     |
| Horizon                          | Not built                | Dashboard for monitoring Redis queue workers and metrics                   |

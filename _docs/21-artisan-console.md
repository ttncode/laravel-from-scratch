# Step 21: Artisan Console

---

## 🚩 The Problem

Administrative tasks — running migrations, clearing caches, seeding data, processing queues — must be triggered from the command line. Without a framework, you write standalone scripts that bootstrap the application manually in each file:

```php
// scripts/clear-cache.php
require __DIR__.'/../vendor/autoload.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'root', '');
$pdo->exec('DELETE FROM cache');

// scripts/seed-users.php
require __DIR__.'/../vendor/autoload.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'root', '');
$pdo->exec("INSERT INTO users ...");
```

**Why is this bad?**

1. **Duplicate bootstrap:** Each script must manually initialise the database, config, and services.
2. **No discoverability:** There is no list of available commands — developers must know the filenames.
3. **No input handling:** Parsing `$argv` for options and arguments is tedious and error-prone.
4. **No output formatting:** Writing colour-coded, progress-bar output manually is non-trivial.
5. **Not the same app:** The script is a different runtime from the HTTP app — bugs diverge.

---

## 💡 The Solution: Console `Application` + `Command` using the same Container

Artisan boots the _same_ Laravel `Application` from `bootstrap/app.php` — then passes it to a Symfony Console `Application` that routes `php artisan <name>` to the correct `Command` class.

```
php artisan migrate
    └── artisan (entry point) ──► bootstrap/app.php (same as HTTP!)
            └── Console\Application::run()
                    └── finds 'migrate' command
                    └── resolves MigrateCommand from container
                    └── MigrateCommand::handle()
                            └── $this->laravel['migrator']->run(...)
```

Every `Command` gets the full container injected — it can use `DB::`, `Cache::`, `Event::` just like a controller.

---

## 🏗 Implementation

### The `artisan` entry point (original)

```php
#!/usr/bin/env php
<?php

// Exactly the same bootstrap as public/index.php:
define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

// But instead of an HTTP kernel, we run the Console kernel:
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->handle(
    $input  = new Symfony\Component\Console\Input\ArgvInput,
    new Symfony\Component\Console\Output\ConsoleOutput
);

$kernel->terminate($input, $status);

exit($status);
```

### `Console\Application` — routes commands

```php
<?php

namespace Illuminate\Console;

use Symfony\Component\Console\Application as SymfonyApplication;

class Application extends SymfonyApplication
{
    /**
     * The Laravel application instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $laravel;

    public function __construct(Container $laravel, Dispatcher $events, $version)
    {
        parent::__construct('Laravel Framework', $version);

        $this->laravel = $laravel;
        $this->events = $events;

        $this->setAutoExit(false);
        $this->setCatchExceptions(false);
    }

    /**
     * Add a command to the console.
     * Resolves the command from the container if given a class name string.
     */
    public function add(SymfonyCommand $command)
    {
        if ($command instanceof Command) {
            $command->setLaravel($this->laravel);
        }

        return parent::add($command);
    }

    /**
     * Resolve an array of commands from the container and add them.
     */
    public function resolve($commands)
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        foreach ($commands as $command) {
            $this->add($this->laravel->make($command));
        }

        return $this;
    }
}
```

### `Command` base class — how you write commands

```php
<?php

namespace Illuminate\Console;

use Symfony\Component\Console\Command\Command as SymfonyCommand;

abstract class Command extends SymfonyCommand
{
    /**
     * The name and signature of the console command.
     * Defines the command name, arguments, and options.
     *
     * @var string
     */
    protected $signature;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description;

    /**
     * The Laravel application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $laravel;

    /**
     * Execute the console command.
     * Subclasses implement this method.
     *
     * @return int
     */
    abstract public function handle();

    /**
     * Get an argument value.
     */
    public function argument($key = null)
    {
        if (is_null($key)) {
            return $this->input->getArguments();
        }

        return $this->input->getArgument($key);
    }

    /**
     * Get an option value.
     */
    public function option($key = null)
    {
        if (is_null($key)) {
            return $this->input->getOptions();
        }

        return $this->input->getOption($key);
    }

    /**
     * Write a string to the output.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        $styled = $style ? "<{$style}>{$string}</{$style}>" : $string;

        $this->output->writeln($styled, $this->parseVerbosity($verbosity));
    }

    public function info($string, $verbosity = null)
    {
        $this->line($string, 'info', $verbosity);
    }

    public function error($string, $verbosity = null)
    {
        $this->line($string, 'error', $verbosity);
    }

    public function warn($string, $verbosity = null)
    {
        $this->line($string, 'comment', $verbosity);
    }

    /**
     * Confirm a question with the user.
     */
    public function confirm($question, $default = false)
    {
        return $this->output->confirm($question, $default);
    }

    /**
     * Call another Artisan command.
     */
    public function call($command, array $arguments = [])
    {
        return $this->runCommand($command, $arguments, $this->output);
    }
}
```

### Writing a custom command

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneOldUsers extends Command
{
    /**
     * The name and signature.
     * {--days=30} defines an optional option with a default.
     *
     * @var string
     */
    protected $signature = 'users:prune {--days=30 : Delete users inactive for this many days}';

    protected $description = 'Delete users who have been inactive for a given number of days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        $this->info("Pruning users inactive for more than {$days} days...");

        // Uses the full container — same DB, same Eloquent, same config as HTTP.
        $count = User::where('last_active_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$count} users.");

        // Return 0 for success, non-zero for failure.
        return self::SUCCESS;
    }
}
```

### Registering commands (in `app/Console/Kernel.php`)

```php
<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by the application.
     *
     * @var array
     */
    protected $commands = [
        Commands\PruneOldUsers::class,
    ];

    /**
     * Define the application's command schedule.
     * Called when artisan schedule:run is invoked by a cron job.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('users:prune --days=60')->daily();
        $schedule->command('queue:work --stop-when-empty')->everyMinute();
    }
}
```

---

## ✅ Verify

```bash
# List all commands:
php artisan list

# Run your custom command:
php artisan users:prune
# Output: Pruning users inactive for more than 30 days...
#         Deleted 5 users.

# With option:
php artisan users:prune --days=90

# Run with dry-run (pretend):
php artisan migrate --pretend
```

---

## 📌 What We Built

| Element               | Purpose                                                                                 |
| --------------------- | --------------------------------------------------------------------------------------- |
| `artisan` entry point | Boots the same `Application` as HTTP; runs `Console\Kernel::handle()`                   |
| `Console\Application` | Symfony `Application` subclass; resolves commands from Laravel's container              |
| `Command`             | Base class for all commands — `handle()`, `argument()`, `option()`, `info()`, `error()` |
| `$signature`          | Defines command name, required `{argument}` and optional `{--option}` in one string     |
| `Console\Kernel`      | Registers commands; defines the `schedule()` for cron-driven tasks                      |
| `Schedule`            | Fluent scheduler — `->daily()`, `->hourly()`, `->everyMinute()` etc.                    |

---

## ⚠️ Simplifications vs Laravel

| Laravel                            | Our Implementation | Reason                                                                                   |
| ---------------------------------- | ------------------ | ---------------------------------------------------------------------------------------- |
| `make:command` generator           | Not built          | Artisan generates new command stubs (it is a command itself)                             |
| `$this->table()`                   | Present in source  | Renders data as a formatted ASCII table in the terminal                                  |
| `$this->withProgressBar()`         | Present in source  | Displays a progress bar for iterating over a large collection                            |
| `$this->ask()` / `$this->secret()` | Present in source  | Prompts the user for input interactively                                                 |
| `schedule:run` + cron              | Not built          | A single `* * * * * php artisan schedule:run` cron replaces many individual cron entries |
| Signals (`pcntl`)                  | Present in source  | Allows the worker to gracefully shut down on `SIGTERM`                                   |

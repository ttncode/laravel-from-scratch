# Step 15: Migrations & Schema

---

## 🚩 The Problem

Your Eloquent models from Step 14 assume the database tables already exist. In a team of 3 developers, the schema lives in someone's head:

- Developer A adds a `phone` column locally and forgets to tell anyone.
- Developer B deploys to production — the `phone` column is missing — runtime errors.
- There is no record of _what_ the schema looked like at any point in time.

The naive fix is a shared SQL dump or a wiki page:

```sql
-- schema.sql (gets out of date immediately)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255)
);
```

**Why is this bad?**

1. **No history:** You cannot see what changed, when, or by whom.
2. **No rollback:** If a bad column is added, restoring the previous state is manual.
3. **No automation:** Every new environment (CI, staging) needs manual SQL setup.

---

## 💡 The Solution: Versioned Migration Files + Migrator

Laravel solves this with **migration files** — PHP classes each describing one incremental schema change. The `Migrator` tracks which files have already run in a `migrations` table.

```
php artisan migrate
    └── Migrator::run()
            └── reads all migration files
            └── skips files already in `migrations` table
            └── calls $migration->up()   ← uses Schema Builder
            └── records file in `migrations` table

php artisan migrate:rollback
    └── Migrator::rollback()
            └── reads last batch from `migrations` table
            └── calls $migration->down()
            └── removes from `migrations` table
```

Each migration file is a plain PHP class:

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

---

## 🏗 Implementation

### File: `Illuminate/Database/Migrations/Migration.php` (original, complete)

```php
<?php

namespace Illuminate\Database\Migrations;

abstract class Migration
{
    /**
     * The name of the database connection to use.
     *
     * @var string|null
     */
    protected $connection;

    /**
     * Enables, if supported, wrapping the migration within a transaction.
     *
     * @var bool
     */
    public $withinTransaction = true;

    /**
     * Get the migration connection name.
     *
     * @return string|null
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Determine if this migration should run.
     *
     * @return bool
     */
    public function shouldRun(): bool
    {
        return true;
    }
}
```

### How `Migrator` tracks and runs migrations

```php
<?php

namespace Illuminate\Database\Migrations;

class Migrator
{
    // The repository records which migrations have already run.
    protected $repository;

    // The filesystem finds migration files in your database/migrations/ dir.
    protected $files;

    // The connection resolver for running migrations.
    protected $resolver;

    public function __construct(
        MigrationRepositoryInterface $repository,
        Resolver $resolver,
        Filesystem $files,
        Dispatcher $dispatcher = null,
    ) {
        $this->files = $files;
        $this->resolver = $resolver;
        $this->repository = $repository;
        $this->events = $dispatcher;
    }

    // Run pending migrations.
    public function run($paths = [], array $options = [])
    {
        $files = $this->getMigrationFiles($paths);

        $this->requireFiles(
            $migrations = $this->pendingMigrations($files, $this->repository->getRan())
        );

        $this->runPending($migrations, $options);

        return $migrations;
    }

    // Find which files haven't been recorded in the migrations table yet.
    protected function pendingMigrations($files, $ran)
    {
        return Collection::make($files)
            ->reject(function ($file) use ($ran) {
                return in_array($this->getMigrationName($file), $ran);
            })->values()->all();
    }

    // Run each pending file and record it in the migrations table.
    public function runPending(array $migrations, array $options = [])
    {
        if (count($migrations) === 0) {
            $this->fireMigrationEvent(new NoPendingMigrations('up'));

            return;
        }

        $batch = $this->repository->getNextBatchNumber();

        foreach ($migrations as $file) {
            $this->runUp($file, $batch, $options['pretend'] ?? false);
        }
    }

    protected function runUp($file, $batch, $pretend)
    {
        $migration = $this->resolvePath($file);

        $name = $this->getMigrationName($file);

        if ($pretend) {
            return $this->pretendToRun($migration, 'up');
        }

        $this->runMigration($migration, 'up');

        // Record this migration as run at this batch number.
        $this->repository->log($name, $batch);
    }

    // Rollback the last batch.
    public function rollback($paths = [], array $options = [])
    {
        $migrations = $this->getMigrationsForRollback($options);

        if (count($migrations) === 0) {
            $this->fireMigrationEvent(new NoPendingMigrations('down'));

            return [];
        }

        return $this->rollbackMigrations($migrations, $paths, $options);
    }

    // Wraps migration in a transaction if the driver supports it.
    protected function runMigration($migration, $method)
    {
        $connection = $this->resolveConnection(
            $migration->getConnection()
        );

        $callback = function () use ($connection, $migration, $method) {
            if (method_exists($migration, $method)) {
                $this->fireMigrationEvent(new MigrationStarted($migration, $method));

                $this->runMethod($connection, $migration, $method);

                $this->fireMigrationEvent(new MigrationEnded($migration, $method));
            }
        };

        $this->getSchemaGrammar($connection)->supportsSchemaTransactions()
        && $migration->withinTransaction
            ? $connection->transaction($callback)
            : $callback();
    }
}
```

### How `Schema\Builder` describes table structure

```php
// This is what Schema::create() does internally:
$this->app['db.schema']->create('users', function (Blueprint $table) {
    $table->id();                        // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    $table->string('name');              // VARCHAR(255)
    $table->string('email')->unique();   // VARCHAR(255) + unique index
    $table->timestamps();                // created_at, updated_at TIMESTAMP
});
```

The `Blueprint` collects column definitions; `Schema\Grammars\Grammar` compiles them to driver-specific `CREATE TABLE` SQL.

### Migration file naming convention

```
database/migrations/
    2024_01_01_000000_create_users_table.php   ← timestamp ensures order
    2024_01_02_000000_add_phone_to_users.php
    2024_01_03_000000_create_posts_table.php
```

The timestamp prefix **is the version number** — it ensures migrations always run in the correct order regardless of alphabetical sort.

---

## ✅ Verify

```bash
# Generate a migration (real Laravel):
php artisan make:migration create_users_table

# Run pending migrations:
php artisan migrate

# Rollback the last batch:
php artisan migrate:rollback

# See which migrations have run:
php artisan migrate:status
```

After `migrate`, inspect the database:

```sql
SELECT * FROM migrations ORDER BY batch, id;
-- Expected:
-- 2024_01_01_000000_create_users_table | 1
```

---

## 📌 What We Built

| Element               | Purpose                                                                         |
| --------------------- | ------------------------------------------------------------------------------- |
| `Migration`           | Base class every migration extends — defines `up()` and `down()` contract       |
| `Migrator`            | Reads migration files, tracks runs in DB, calls `up()`/`down()` in order        |
| `MigrationRepository` | Reads/writes the `migrations` table (which batch each file belongs to)          |
| `Schema\Builder`      | Fluent API for `create`, `drop`, `alter` — compiles to driver SQL via `Grammar` |
| `Blueprint`           | Collects column/index definitions for one `CREATE TABLE` or `ALTER TABLE`       |

---

## ⚠️ Simplifications vs Laravel

| Laravel                       | Our Implementation | Reason                                                           |
| ----------------------------- | ------------------ | ---------------------------------------------------------------- |
| `php artisan make:migration`  | Not built          | Artisan command generates the file from a stub (Step 21)         |
| `Schema::table()` for `ALTER` | Not built          | Adds/modifies columns on existing tables                         |
| `$table->foreign()`           | Not built          | Declares foreign key constraints and cascade rules               |
| Batch rollback                | Present in source  | Rollback reverts the _last batch_, not just the last file        |
| Migration squashing           | Not built          | Collapses many migrations into a single SQL dump for performance |

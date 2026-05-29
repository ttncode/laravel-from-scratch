# Step 13: Database & Query Builder

---

## 🚩 The Problem

After Step 12, your app can validate input — but it still has nowhere to persist data. The naive approach is raw PDO scattered across controllers:

```php
public function store(Request $request)
{
    $pdo = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
    $stmt = $pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
    $stmt->execute([$request->input('name'), $request->input('email')]);
}

public function index()
{
    $pdo = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
    $stmt = $pdo->query('SELECT * FROM users WHERE active = 1 ORDER BY name');
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}
```

**Why is this bad?**

1. **No connection reuse:** Every method creates a new PDO connection — expensive.
2. **String concatenation danger:** Building `WHERE id = $id` directly leads to SQL injection.
3. **No fluency:** Adding `ORDER BY`, `LIMIT`, `JOIN` means rewriting entire SQL strings.
4. **Driver coupling:** Switching from SQLite to MySQL requires rewriting every query connection and dialect.

---

## 💡 The Solution: DatabaseManager + Connection + Query Builder

We split the database layer into three collaborating classes inside the `Framework\Database` namespace:

```
DatabaseServiceProvider
    └── registers 'db' → DatabaseManager
            └── connection($name) → Connection (wraps PDO)
                    └── table($table) → Query\Builder (fluent SQL compilation & execution)
```

- **`DatabaseManager`** — resolves and manages database connections, lazy-connecting as needed using configurations loaded from `config/database.php`.
- **`Connection`** — wraps a PDO instance and provides safe query execution methods with parameterized bindings.
- **`Query\Builder`** — provides a fluent, driver-agnostic SQL query builder interface and compiles queries to SQL dynamically.

Usage in a controller:

```php
// Via the container:
$users = $this->app->make('db')->table('users')->where('name', 'Alice')->get();
```

---

## 🏗 Implementation

Let's create the directories and files we need:

```bash
mkdir -p src/Database/Query
touch src/Database/DatabaseServiceProvider.php
touch src/Database/DatabaseManager.php
touch src/Database/Connection.php
touch src/Database/Query/Builder.php
```

### File: `src/Database/DatabaseServiceProvider.php`

The Service Provider that registers our database connection services in the Container.

```php
<?php

namespace Framework\Database;

use Framework\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any database services.
     */
    public function boot(): void
    {
        // Eloquent ORM connection resolver configuration will be added here in Step 14
    }

    /**
     * Register any database services in the Container.
     */
    public function register(): void
    {
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app);
        });

        $this->app->bind('db.connection', function ($app) {
            return $app->make('db')->connection();
        });
    }
}
```

---

### File: `src/Database/DatabaseManager.php`

The manager class that resolves named database connections based on config, caching opened PDO connections for reuse.

```php
<?php

namespace Framework\Database;

use PDO;
use InvalidArgumentException;
use Framework\Config\Repository as ConfigRepository;

class DatabaseManager
{
    protected $app;
    protected array $connections = [];

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Resolve a connection instance by name.
     */
    public function connection(?string $name = null): Connection
    {
        $name = $name ?: $this->getDefaultConnection();

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->makeConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Create a new Connection instance.
     */
    protected function makeConnection(string $name): Connection
    {
        $config = $this->configuration($name);
        $pdo = $this->createPdoConnection($config);

        return new Connection($pdo, $name);
    }

    /**
     * Create a concrete PDO instance.
     */
    protected function createPdoConnection(array $config): PDO
    {
        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $dbPath = $config['database'];
            return new PDO("sqlite:{$dbPath}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        }

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '3306',
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );

            return new PDO($dsn, $config['username'] ?? 'root', $config['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        }

        throw new InvalidArgumentException("Unsupported database driver: [{$driver}]");
    }

    /**
     * Get the connection configuration array.
     */
    protected function configuration(string $name): array
    {
        $configRepo = $this->app->make(ConfigRepository::class);
        $connections = $configRepo->get('database.connections');

        if (! isset($connections[$name])) {
            throw new InvalidArgumentException("Database connection [{$name}] not configured.");
        }

        return $connections[$name];
    }

    /**
     * Get the default connection name from config.
     */
    public function getDefaultConnection(): string
    {
        return $this->app->make(ConfigRepository::class)->get('database.default', 'mysql');
    }

    /**
     * Dynamically forward method calls to the default connection.
     * This allows $db->table('users')->... calls.
     */
    public function __call(string $method, array $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}
```

---

### File: `src/Database/Connection.php`

The Connection class wraps the raw PDO instance, handles executing query statements safely, and boots the Query Builder.

```php
<?php

namespace Framework\Database;

use PDO;
use Framework\Database\Query\Builder;

class Connection
{
    protected PDO $pdo;
    protected string $name;

    public function __construct(PDO $pdo, string $name = '')
    {
        $this->pdo = $pdo;
        $this->name = $name;
    }

    /**
     * Start a fluent Query Builder against a table.
     */
    public function table(string $table): Builder
    {
        return new Builder($this, $table);
    }

    /**
     * Execute a SELECT statement with bound values and return results.
     */
    public function select(string $query, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($query);
        $statement->execute($bindings);
        return $statement->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Execute an INSERT statement with bound values and return status.
     */
    public function insert(string $query, array $bindings = []): bool
    {
        $statement = $this->pdo->prepare($query);
        return $statement->execute($bindings);
    }

    /**
     * Get the underlying raw PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
```

---

### File: `src/Database/Query/Builder.php`

The fluent Query Builder compiling PHP methods into structured, parameterized SQL queries safely.

```php
<?php

namespace Framework\Database\Query;

use Framework\Database\Connection;

class Builder
{
    protected Connection $connection;
    protected string $table;
    protected array $columns = ['*'];
    protected array $wheres = [];
    protected array $orders = [];
    protected ?int $limit = null;
    protected array $bindings = [];

    public function __construct(Connection $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    /**
     * Set the columns to select.
     */
    public function select(...$columns): self
    {
        $this->columns = is_array($columns[0] ?? null) ? $columns[0] : $columns;
        return $this;
    }

    /**
     * Add a basic WHERE clause with bindings.
     */
    public function where(string $column, $operator, $value = null): self
    {
        // If only two arguments are passed, assume '=' as the operator
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add an ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc',
        ];
        return $this;
    }

    /**
     * Add a LIMIT clause.
     */
    public function limit(int $value): self
    {
        $this->limit = $value;
        return $this;
    }

    /**
     * Compile the SELECT query and fetch all matching records.
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings);
    }

    /**
     * Compile and execute an INSERT statement.
     */
    public function insert(array $values): bool
    {
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            $placeholders
        );

        return $this->connection->insert($sql, array_values($values));
    }

    /**
     * Compile the fluent query parameters into a standard SQL string.
     */
    public function toSql(): string
    {
        $sql = sprintf('SELECT %s FROM %s', implode(', ', $this->columns), $this->table);

        if (! empty($this->wheres)) {
            $clauses = array_map(function ($where) {
                return sprintf('%s %s ?', $where['column'], $where['operator']);
            }, $this->wheres);
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if (! empty($this->orders)) {
            $clauses = array_map(function ($order) {
                return sprintf('%s %s', $order['column'], $order['direction']);
            }, $this->orders);
            $sql .= ' ORDER BY ' . implode(', ', $clauses);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        return $sql;
    }
}
```

---

## ⚙️ Configuration

Now, register the service provider in the Application bootstrap process, define the database connection configuration, and set up your database environment variables.

### 1. Register the Database Service Provider

Update `src/Foundation/Bootstrap/RegisterProviders.php` to include our new `DatabaseServiceProvider`:

```php
        $providers = [
            \App\Providers\AppServiceProvider::class,
            \Framework\Database\DatabaseServiceProvider::class, // <-- ADD THIS
        ];
```

### 2. Add `config/database.php`

Create `config/database.php` to define your application's database connections:

```php
<?php

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', __DIR__ . '/../database/database.sqlite'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel_from_scratch'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
        ],
    ],
];
```

### 3. Add Environment Variables to `.env`

Append these variables to your `.env` file:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/home/ttndev/workspace/personal/laravel-from-scratch/database/database.sqlite
```

_(Note: Create the database folder and database.sqlite file if they don't exist: `mkdir -p database && touch database/database.sqlite`)_

---

## ✅ Verify

Add a test route to your `routes/web.php` file:

```php
$router->get('/db-test', function () use ($app) {
    $db = $app->make('db');

    // Make sure we have a clean test table
    $pdo = $db->connection()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        email TEXT
    )");

    // Insert using fluent Query Builder
    $db->table('users')->insert([
        'name'  => 'Alice',
        'email' => 'alice@example.com',
    ]);

    // Select using fluent Query Builder
    $users = $db->table('users')
        ->select('id', 'name', 'email')
        ->where('name', 'Alice')
        ->orderBy('id', 'desc')
        ->limit(5)
        ->get();

    return new \Framework\Http\Response(json_encode($users), 200, [
        'Content-Type' => 'application/json'
    ]);
});
```

Run your development server:

```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/db-test` in your browser. You should receive a clean JSON payload containing the inserted database records:

```json
[
	{
		"id": 1,
		"name": "Alice",
		"email": "alice@example.com"
	}
]
```

---

## 📌 What We Built

| Element                   | Purpose                                                                     |
| :------------------------ | :-------------------------------------------------------------------------- |
| `DatabaseServiceProvider` | Connects the DatabaseManager and open connections to the container.         |
| `DatabaseManager`         | Manages multiple driver connections lazily, caching PDO instances.          |
| `Connection`              | Encapsulates the native `PDO` instance, ensuring secure prepared execution. |
| `Query\Builder`           | A fluent, driver-agnostic query interface that compiles SQL on demand.      |

---

## ⚠️ Simplifications vs Laravel

| Laravel                      | Our Implementation                        | Reason                                                                                                                       |
| :--------------------------- | :---------------------------------------- | :--------------------------------------------------------------------------------------------------------------------------- |
| `Grammar` classes per driver | Compiled inline inside `Builder::toSql()` | A full separate grammar system supporting PostgreSQL, SQLite, MSSQL and MySQL dialects adds hundreds of classes of overhead. |
| `Processor` classes          | Omitted, using standard PDO fetch modes   | Handles driver-specific post-processing (e.g., returning auto-increment IDs).                                                |
| Read/Write split             | Single PDO connection resolved            | Advanced high-scale capability for replicating load across master/replica setups.                                            |
| Database Transactions        | Handled via direct PDO methods if needed  | We omitted a transaction manager class (`DatabaseTransactionsManager`) to keep container services simple.                    |

---

**Next:** [Step 14 — Eloquent ORM →](./14-eloquent-orm.md)

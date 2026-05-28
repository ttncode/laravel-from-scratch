# Step 13: Database & Query Builder

---

## 🚩 The Problem

After Step 12, your app can validate input — but it still has nowhere to persist data. The naive approach is raw PDO scattered across controllers:

```php
public function store(Request $request)
{
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'root', '');
    $stmt = $pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
    $stmt->execute([$request->input('name'), $request->input('email')]);
}

public function index()
{
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'root', '');
    $stmt = $pdo->query('SELECT * FROM users WHERE active = 1 ORDER BY name');
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}
```

**Why is this bad?**

1. **No connection reuse:** Every method creates a new PDO connection — expensive.
2. **String concatenation danger:** Building `WHERE id = $id` directly leads to SQL injection.
3. **No fluency:** Adding `ORDER BY`, `LIMIT`, `JOIN` means rewriting entire SQL strings.
4. **Driver coupling:** Switching from MySQL to SQLite requires rewriting every query.

---

## 💡 The Solution: DatabaseManager + Connection + Query Builder

Laravel splits the database layer into three collaborating objects:

```
DatabaseServiceProvider
    └── registers 'db' → DatabaseManager
            └── connection($name) → Connection (wraps PDO)
                    └── table($table) → Query\Builder (fluent SQL)
```

- **`DatabaseManager`** — resolves named connections from config; lazy-connects.
- **`Connection`** — owns a PDO instance; executes raw SQL with bindings.
- **`Query\Builder`** — fluent API that compiles to parameterised SQL.

Usage in a controller:

```php
// Via the container:
$users = $this->app['db']->table('users')->where('active', 1)->get();

// Via the DB facade (Step 16):
$users = DB::table('users')->where('active', 1)->get();
```

---

## 🏗 Implementation

### File: `DatabaseServiceProvider.php` (original, simplified)

```php
<?php

namespace Illuminate\Database;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Model::setConnectionResolver($this->app['db']);

        Model::setEventDispatcher($this->app['events']);
    }

    public function register()
    {
        Model::clearBootedModels();

        $this->registerConnectionServices();
    }

    protected function registerConnectionServices()
    {
        // The connection factory creates actual PDO connections on demand.
        $this->app->singleton('db.factory', function ($app) {
            return new ConnectionFactory($app);
        });

        // The database manager resolves named connections from config.
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $this->app->bind('db.connection', function ($app) {
            return $app['db']->connection();
        });

        $this->app->bind('db.schema', function ($app) {
            return $app['db']->connection()->getSchemaBuilder();
        });

        $this->app->singleton('db.transactions', function () {
            return new DatabaseTransactionsManager;
        });
    }
}
```

### How `DatabaseManager::connection()` works

```php
<?php

namespace Illuminate\Database;

class DatabaseManager implements ConnectionResolverInterface
{
    protected $app;
    protected $factory;
    protected $connections = [];

    public function __construct($app, ConnectionFactory $factory)
    {
        $this->app = $app;
        $this->factory = $factory;

        $this->reconnector = function ($connection) {
            $connection->setPdo(
                $this->reconnect($connection->getNameWithReadWriteType())->getRawPdo()
            );
        };
    }

    public function connection($name = null)
    {
        [$database, $type] = $this->parseConnectionName($name = $name ?: $this->getDefaultConnection());

        // Connections are cached — only one PDO per named connection.
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->configure(
                $this->makeConnection($database), $type
            );
        }

        return $this->connections[$name];
    }

    public function getDefaultConnection()
    {
        return $this->app['config']['database.default'];
    }

    // __call forwards any unknown method to the default connection.
    // This is why DB::table() works via the facade.
    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}
```

### How `Connection` executes queries

```php
<?php

namespace Illuminate\Database;

class Connection implements ConnectionInterface
{
    protected $pdo;
    protected $database;
    protected $tablePrefix = '';
    protected $config = [];
    protected $queryGrammar;
    protected $postProcessor;

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        $this->pdo = $pdo;
        $this->database = $database;
        $this->tablePrefix = $tablePrefix;
        $this->config = $config;

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
    }

    // Entry point for the fluent Query Builder.
    public function table($table, $as = null)
    {
        return $this->query()->from($table, $as);
    }

    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    // Executes a SELECT — uses prepared statements with bound values.
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo, $fetchUsing) {
            $statement = $this->prepared(
                $this->getPdoForSelect($useReadPdo)->prepare($query)
            );

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            return $statement->fetchAll(...$fetchUsing);
        });
    }

    // Binds values safely — prevents SQL injection.
    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR
                },
            );
        }
    }
}
```

### How `Query\Builder` builds fluent queries

```php
<?php

namespace Illuminate\Database\Query;

class Builder implements BuilderContract
{
    public $connection;
    public $grammar;
    public $processor;

    public $bindings = [
        'select' => [], 'from' => [], 'join' => [],
        'where' => [], 'groupBy' => [], 'having' => [],
        'order' => [], 'union' => [], 'unionOrder' => [],
    ];

    public $columns;
    public $from;
    public $wheres = [];
    public $orders;
    public $limit;
    public $offset;

    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
    ) {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
        $this->processor = $processor ?: $connection->getPostProcessor();
    }

    public function select($columns = ['*'])
    {
        $this->columns = [];
        $this->bindings['select'] = [];

        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $as => $column) {
            $this->columns[] = $column;
        }

        return $this;
    }

    public function from($table, $as = null)
    {
        $this->from = $as ? "{$table} as {$as}" : $table;

        return $this;
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        $join = $this->newJoinClause($this, $type, $table);

        if ($first instanceof Closure) {
            $first($join);
            $this->joins[] = $join;
            $this->addBinding($join->getBindings(), 'join');
        } else {
            $method = $where ? 'where' : 'on';
            $this->joins[] = $join->$method($first, $operator, $second);
            $this->addBinding($join->getBindings(), 'join');
        }

        return $this;
    }

    public function leftJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function distinct()
    {
        $columns = func_get_args();

        if ($columns !== []) {
            $this->distinct = is_array($columns[0]) || is_bool($columns[0]) ? $columns[0] : $columns;
        } else {
            $this->distinct = true;
        }

        return $this;
    }
}
```

---

## ✅ Verify

Add to `routes/web.php`:

```php
$router->get('/db-test', function () use ($app) {
    $db = $app['db'];

    // Insert
    $db->table('users')->insert([
        'name'  => 'Alice',
        'email' => 'alice@example.com',
    ]);

    // Select with fluent builder
    $users = $db->table('users')
        ->select('id', 'name', 'email')
        ->where('name', 'Alice')
        ->orderBy('id', 'desc')
        ->limit(5)
        ->get();

    return json_encode($users);
});
```

Run: `php -S 0.0.0.0:8000 -t public` and open `http://localhost:8000/db-test`.

---

## 📌 What We Built

| Element                   | Purpose                                                                        |
| ------------------------- | ------------------------------------------------------------------------------ |
| `DatabaseManager`         | Resolves named connections lazily from config; caches open connections         |
| `Connection`              | Owns PDO; executes raw SQL with safe parameterised bindings                    |
| `Query\Builder`           | Fluent, driver-agnostic SQL builder; compiles to SQL via `Grammar`             |
| `DatabaseServiceProvider` | Wires all three into the container as `'db'`, `'db.connection'`, `'db.schema'` |

---

## ⚠️ Simplifications vs Laravel

| Laravel                                        | Our Implementation         | Reason                                                                             |
| ---------------------------------------------- | -------------------------- | ---------------------------------------------------------------------------------- |
| `Grammar` per driver (MySQL, Postgres, SQLite) | Not built — reuse original | Each driver dialect needs its own `Grammar`; not core to understanding the pattern |
| `Processor` post-processes results             | Not built                  | Handles driver-specific quirks like returning last insert ID                       |
| Read/write split (`::read`, `::write`)         | Not built                  | Production concern for replica databases                                           |
| `DB::listen()` query logging                   | Not built                  | Hooks into `QueryExecuted` event                                                   |

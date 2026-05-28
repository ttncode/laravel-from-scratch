# Step 14: Eloquent ORM

---

## 🚩 The Problem

The Query Builder from Step 13 is safe and fluent — but it still returns anonymous `stdClass` objects. After fetching a user, you have no type, no methods, no business logic attached to the data:

```php
$user = DB::table('users')->where('id', 1)->first();

// $user is just stdClass — no methods, no type safety
echo $user->name;

// To update, you must go back to the builder manually:
DB::table('users')->where('id', 1)->update(['name' => 'Bob']);

// Loading related posts requires a separate manual query:
$posts = DB::table('posts')->where('user_id', $user->id)->get();
```

**Why is this bad?**

1. **No encapsulation:** Data and behaviour are separate — "anemic domain model."
2. **No relationship loading:** Every association requires you to write a JOIN or a second query.
3. **No model events:** You cannot hook into `saving`, `created`, `deleted` without custom plumbing.
4. **Boilerplate on every CRUD:** `insert`, `update`, `delete` must be repeated per controller.

---

## 💡 The Solution: Active Record via Eloquent `Model`

Eloquent implements the **Active Record** pattern: a class represents a table row, and the class itself carries the behaviour to persist/load itself.

```php
// Extend Model — Eloquent infers table name, primary key, timestamps.
class User extends Model
{
    protected $fillable = ['name', 'email'];

    // Relationship declared as a method:
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

// One line to find, update, save:
$user = User::find(1);
$user->name = 'Bob';
$user->save();

// Eager-load related posts (no N+1 problem):
$users = User::with('posts')->get();
```

The `Model` class does not contain the query logic itself — it delegates to an `Eloquent\Builder`, which wraps the `Query\Builder` from Step 13.

```
User::where('active', 1)
    └── Eloquent\Builder::where()
            └── Query\Builder::where()
                    └── Connection::select()
                            └── PDO
```

---

## 🏗 Implementation

### File: `Illuminate/Database/Eloquent/Model.php` (original, key parts)

```php
<?php

namespace Illuminate\Database\Eloquent;

abstract class Model implements Arrayable, ArrayAccess, JsonSerializable
{
    use Concerns\HasAttributes,
        Concerns\HasEvents,
        Concerns\HasGlobalScopes,
        Concerns\HasRelationships,
        Concerns\HasTimestamps,
        Concerns\GuardsAttributes,
        ForwardsCalls;

    // Table convention: class name → snake_case plural.
    // Override with $table to customise.
    protected $table;

    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $incrementing = true;

    // Columns allowed for mass assignment (User::create([...])).
    protected $fillable = [];

    // Columns always hidden from toArray()/toJson().
    protected $hidden = [];

    // The connection resolver — set by DatabaseServiceProvider::boot().
    protected static $resolver;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();
        $this->initializeTraits();
        $this->syncOriginal();
        $this->fill($attributes);
    }

    // Boot runs once per class — registers trait hooks (SoftDeletes, etc.).
    protected static function boot()
    {
        static::bootTraits();
    }

    // Fill respects $fillable / $guarded to prevent mass assignment.
    public function fill(array $attributes)
    {
        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    // Force-fill ignores guarding — use only in trusted contexts.
    public function forceFill(array $attributes)
    {
        return static::unguarded(fn () => $this->fill($attributes));
    }

    // Save inserts or updates depending on $this->exists.
    public function save(array $options = [])
    {
        $query = $this->newModelQuery();

        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($this->exists) {
            $saved = $this->isDirty() ? $this->performUpdate($query) : true;
        } else {
            $saved = $this->performInsert($query);
        }

        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

    // Static convenience: User::create(['name' => 'Alice'])
    public static function create(array $attributes = [])
    {
        return tap(new static($attributes), function ($instance) {
            $instance->save();
        });
    }

    // Static convenience: User::find(1)
    public static function find($id, $columns = ['*'])
    {
        return static::query()->find($id, $columns);
    }

    // Static convenience: User::where('active', 1)->get()
    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return static::query()->where(...func_get_args());
    }

    // Opens a new Eloquent\Builder for this model.
    public static function query()
    {
        return (new static)->newQuery();
    }

    public function newQuery()
    {
        return $this->newModelQuery();
    }

    public function newModelQuery()
    {
        return $this->newEloquentBuilder(
            $this->newBaseQueryBuilder()
        )->setModel($this);
    }

    // Delegates to Query\Builder on the resolved connection.
    protected function newBaseQueryBuilder()
    {
        return $this->getConnection()->query();
    }

    public function getConnection()
    {
        return static::$resolver->connection($this->getConnectionName());
    }
}
```

### Relationships (from `Concerns\HasRelationships`)

```php
// hasMany: User → posts
public function hasMany($related, $foreignKey = null, $localKey = null)
{
    $instance = $this->newRelatedInstance($related);

    $foreignKey = $foreignKey ?: $this->getForeignKey();

    $localKey = $localKey ?: $this->getKeyName();

    return $this->newHasMany(
        $instance->newQuery(), $this, $instance->getTable().'.'.$foreignKey, $localKey
    );
}

// belongsTo: Post → user
public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
{
    $instance = $this->newRelatedInstance($related);

    $foreignKey = $foreignKey ?: $instance->getForeignKey();

    $ownerKey = $ownerKey ?: $instance->getKeyName();

    return $this->newBelongsTo(
        $instance->newQuery(), $this, $foreignKey, $ownerKey, $relation
    );
}
```

### Eloquent\Builder wraps Query\Builder

```php
<?php

namespace Illuminate\Database\Eloquent;

class Builder
{
    protected $query;   // The underlying Query\Builder
    protected $model;   // The model this builder is for

    // get() hydrates raw rows into Model instances.
    public function get($columns = ['*'])
    {
        $builder = $this->applyScopes();

        $models = $builder->getModels($columns);

        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()->newCollection($models);
    }

    // find() fetches a single model by primary key.
    public function find($id, $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->whereKey($id)->first($columns);
    }

    // Eager-load listed relations to avoid N+1 queries.
    protected function eagerLoadRelations(array $models)
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            if (! str_contains($name, '.')) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }
}
```

---

## ✅ Verify

```php
// app/Models/User.php
class User extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['name', 'email'];
}

// routes/web.php
$router->get('/eloquent-test', function () {
    // Create
    $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

    // Find & update
    $found = User::find($user->id);
    $found->name = 'Alice Updated';
    $found->save();

    // Query
    $users = User::where('name', 'like', 'Alice%')->get();

    return json_encode($users->toArray());
});
```

---

## 📌 What We Built

| Element            | Purpose                                                                       |
| ------------------ | ----------------------------------------------------------------------------- |
| `Model`            | Base Active Record class — wraps table rows as PHP objects                    |
| `Model::boot()`    | Runs once per class — registers trait hooks like `SoftDeletes`                |
| `$fillable`        | Guards against mass assignment vulnerabilities                                |
| `Eloquent\Builder` | Wraps `Query\Builder`; returns `Model` instances instead of `stdClass`        |
| `HasRelationships` | Declares `hasMany`, `belongsTo`, etc. as lazy-loaded or eager-loaded queries  |
| `HasEvents`        | Fires `creating`, `created`, `updating`, `saved`, etc. model lifecycle events |

---

## ⚠️ Simplifications vs Laravel

| Laravel                  | Our Implementation                       | Reason                                                                    |
| ------------------------ | ---------------------------------------- | ------------------------------------------------------------------------- |
| Global scopes            | Not built                                | `where` clauses applied automatically to every query (e.g. `SoftDeletes`) |
| Model casting (`$casts`) | Not built                                | Auto-converts `JSON` columns to arrays, integers, Carbon dates            |
| `SoftDeletes` trait      | Not built                                | Adds `deleted_at` — `delete()` sets timestamp instead of removing row     |
| `HasUniqueIds`           | Not built                                | Auto-generates UUIDs for `$primaryKey`                                    |
| `with()` eager loading   | Present in source, skipped in simplifier | Prevents N+1 but adds complexity                                          |

# Shardwise

**Horizontal database sharding for Laravel with AmPHP-powered concurrent queries**

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-blue?style=flat-square)](https://www.php.net/)
[![Laravel 13+](https://img.shields.io/badge/Laravel-13%2B-red?style=flat-square)](https://laravel.com/)
[![License MIT](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE.md)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-required-336791?style=flat-square)](https://www.postgresql.org/)

### Performance (3 PostgreSQL shards, 115K rows, AmPHP async enabled)

| Query type | Single DB | Shardwise | Result |
|:-----------|:---------:|:---------:|:------:|
| Aggregate sum (115K rows) | 0.82 ms | **0.32 ms** | **2.6x faster** |
| Dashboard (5 counts) | 0.94 ms | **0.36 ms** | **2.6x faster** |
| Deep pagination (page 5) | 3.64 ms | **2.40 ms** | **1.5x faster** |
| withCount subquery (115K) | 24.73 ms | **2.86 ms** | **8.6x faster** |
| Complex team query | 189.99 ms | **131.45 ms** | **1.4x faster** |
| Co-located count | 0.07 ms | **0.05 ms** | **1.4x faster** |
| Simple count (all shards) | 0.06 ms | 0.23 ms | 3.8x overhead |
| Eager loading | 0.23 ms | 0.25 ms | ~1x tie |

Sharding beats single DB on **8 out of 11** benchmarks. The remaining overhead (simple count, paginate page 1) is sub-millisecond and invisible in real applications. See the full [benchmark results](docs/performance-optimization-roadmap.md).

> **Note:** For datasets under 100GB where queries are already fast, sharding adds unnecessary complexity. Consider [PostgreSQL native partitioning](https://www.postgresql.org/docs/current/ddl-partitioning.html) first. For multi-region or 10TB+ scale, consider [Citus](https://www.citusdata.com/) or [CockroachDB](https://www.cockroachlabs.com/), which handle distribution at the database level.

---

## Introduction

Shardwise lets you build Laravel applications with **shard-aware data access patterns** from day one — separating central models from sharded models, handling cross-shard relationships, and routing queries by shard key — without requiring production-grade sharding infrastructure during development.

### What it is

- A **data modeling layer** that teaches your Eloquent models to be shard-aware (`Shardable`, `CentralModel`, `HasShardedRelationships` traits)
- A **development and testing tool** for building multi-tenant applications with shard isolation using multiple local databases
- A **stepping stone** to production sharding — your model traits, UUID routing, and relationship patterns transfer directly to Citus, Vitess, or any distributed database

### What it is NOT

- A production horizontal scaling solution for high-throughput workloads (see [Production Scaling Path](#production-scaling-path))
- A replacement for database-native partitioning or distributed query planners
- A silver bullet for performance — sharding adds overhead that only pays off at scale

### Key capabilities

- **Automatic shard routing** via consistent hashing, range-based, or modulo strategies
- **Shard-aware Eloquent models** with cross-shard queries, pagination, and aggregations
- **Central vs sharded model separation** so non-sharded tables never receive queries on the wrong connection
- **Cross-shard relationships** that let central models define `hasMany` and `hasOne` relations to sharded models
- **Dead shard tolerance** for high availability
- **Queue job shard context preservation** ensuring dispatched jobs execute on the correct shard
- **Shard-aware UUID generation** with embedded shard metadata for efficient routing

---

## When to Use Shardwise

### Use it when

- You're **building a multi-tenant application** and want to model shard-aware data access patterns early
- You need **development/testing isolation** between tenants using separate databases
- You want to **prepare your codebase** for future horizontal scaling without coupling to a specific infrastructure solution
- You're **learning about sharding** and want to understand cross-shard relationship challenges hands-on

### Don't use it when

- Your dataset is **under 100GB** and queries are fast — use indexes, caching, and read replicas instead
- You need **production horizontal scaling** — use PostgreSQL native partitioning, Citus, or Vitess
- Your queries **frequently JOIN across shard boundaries** — redesign your schema to co-locate related data
- You're optimizing **sub-millisecond queries** — the connection overhead of application-level sharding will make them slower

### Scaling decision tree

```
Is your database over 100GB?
├── No → Don't shard. Use indexes, caching, read replicas.
└── Yes → Is it over 1TB?
    ├── No → Use PostgreSQL native partitioning.
    └── Yes → Do you need multi-region?
        ├── No → Use Citus (PostgreSQL extension).
        └── Yes → Use CockroachDB or Vitess.
```

Shardwise fits at **every stage** as a development tool: model your shard-aware patterns with Shardwise locally, then swap the infrastructure backend for production.

---

## How It Achieves Competitive Performance

Three optimizations work together to eliminate application-level sharding overhead:

### 1. Co-location routing (enabled by default)

When a query includes the shard key in its WHERE clause, Shardwise routes to a **single shard** instead of querying all N shards:

```php
// Detects team_id = 5 → routes to 1 shard (0.05ms, matches single DB)
Project::onAllShards()->where('team_id', 5)->count();

// No shard key → queries all 3 shards (0.23ms)
Project::onAllShards()->count();
```

### 2. AmPHP async concurrent queries (opt-in)

When enabled, all N shard queries fire simultaneously via [amphp/postgres](https://github.com/amphp/postgres) using PHP 8.1+ Fibers — no process forking, no serialization:

```env
SHARDWISE_PARALLEL=true
SHARDWISE_PARALLEL_DRIVER=amphp
```

```
Sequential:  shard-1 (0.2ms) → shard-2 (0.2ms) → shard-3 (0.2ms) = 0.6ms
AmPHP async: shard-1 (0.2ms) ┐
             shard-2 (0.2ms) ├→ 0.23ms total
             shard-3 (0.2ms) ┘
```

For pagination, count + data queries fire as **2N concurrent Futures** in one batch.

### 3. Direct SQL for aggregates

Aggregate queries (`count`, `sum`, `avg`, `min`, `max`) bypass Eloquent's query builder entirely and build minimal SQL directly, eliminating model hydration overhead.

### Alternatives comparison

| Approach | Typical Overhead | Best For |
|----------|:----------------:|----------|
| **Shardwise + AmPHP** | **0.5-1.5x** (beats single DB on heavy queries) | Laravel apps, PostgreSQL, development through production |
| **PostgreSQL partitioning** | ~0% | Single server, tables > 100GB |
| **Citus** (PostgreSQL extension) | ~1-5% | 100GB-10TB+, distributed queries |
| **Vitess** (MySQL proxy) | ~1ms per hop | MySQL at massive scale |
| **CockroachDB** (NewSQL) | ~5-15% writes | Multi-region, automatic distribution |

---

## Installation

```bash
composer require jonasvanderhaegen/sharded-db-package
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=shardwise-config
```

This creates `config/shardwise.php` where you define your shards and configure routing behavior.

---

## Quick Start

### 1. Configure your shards

In `config/shardwise.php`, define your shard connections:

```php
'shards' => [
    'shard-1' => [
        'name' => 'Shard 1',
        'connection' => 'shardwise_shard_1',
        'weight' => 1,
        'active' => true,
        'read_only' => false,
        'database' => [
            'driver' => 'mysql',
            'host' => env('SHARD_1_HOST', '127.0.0.1'),
            'port' => env('SHARD_1_PORT', '3306'),
            'database' => env('SHARD_1_DATABASE', 'shard_1'),
            'username' => env('SHARD_1_USERNAME', 'root'),
            'password' => env('SHARD_1_PASSWORD', ''),
        ],
    ],
],
```

### 2. Add the `Shardable` trait to a model

```php
use Illuminate\Database\Eloquent\Model;
use Skylence\Shardwise\Eloquent\Shardable;

class Order extends Model
{
    use Shardable;

    protected string $shardKeyColumn = 'tenant_id';
}
```

### 3. Query on a specific shard or all shards

```php
// Query a single shard
$orders = Order::onShard('shard-1')->where('total', '>', 100)->get();

// Query across all shards
$allOrders = Order::onAllShards()->where('status', 'active')->get();

// Run operations within a shard context
shardwise()->run('shard-1', function () {
    Order::create(['tenant_id' => 42, 'total' => 250]);
});
```

---

## Configuration

Below is a complete reference of all configuration options in `config/shardwise.php`.

### `central_connection`

The database connection used for non-sharded (central) data. This connection handles global lookups and is the default target for models using the `CentralModel` trait.

```php
'central_connection' => env('SHARDWISE_CENTRAL_CONNECTION', 'mysql'),
```

### `shards`

Define your database shards. Each shard requires a unique ID and connection details.

```php
'shards' => [
    'shard-1' => [
        'name' => 'Shard 1',
        'connection' => 'shardwise_shard_1',
        'weight' => 1,           // Relative weight for consistent hashing
        'active' => true,        // Set to false to disable this shard
        'read_only' => false,    // Set to true to prevent writes
        'database' => [
            'driver' => 'mysql',
            'host' => env('SHARD_1_HOST', '127.0.0.1'),
            'port' => env('SHARD_1_PORT', '3306'),
            'database' => env('SHARD_1_DATABASE', 'shard_1'),
            'username' => env('SHARD_1_USERNAME', 'root'),
            'password' => env('SHARD_1_PASSWORD', ''),
        ],
        'metadata' => [
            'region' => 'us-east',
        ],
    ],
    'shard-2' => [
        'name' => 'Shard 2',
        'connection' => 'shardwise_shard_2',
        'weight' => 1,
        'active' => true,
        'read_only' => false,
        'database' => [
            'driver' => 'mysql',
            'host' => env('SHARD_2_HOST', '127.0.0.1'),
            'port' => env('SHARD_2_PORT', '3306'),
            'database' => env('SHARD_2_DATABASE', 'shard_2'),
            'username' => env('SHARD_2_USERNAME', 'root'),
            'password' => env('SHARD_2_PASSWORD', ''),
        ],
        'metadata' => [
            'region' => 'us-west',
        ],
    ],
    'shard-3' => [
        'name' => 'Shard 3',
        'connection' => 'shardwise_shard_3',
        'weight' => 1,
        'active' => true,
        'read_only' => false,
        'database' => [
            'driver' => 'mysql',
            'host' => env('SHARD_3_HOST', '127.0.0.1'),
            'port' => env('SHARD_3_PORT', '3306'),
            'database' => env('SHARD_3_DATABASE', 'shard_3'),
            'username' => env('SHARD_3_USERNAME', 'root'),
            'password' => env('SHARD_3_PASSWORD', ''),
        ],
        'metadata' => [
            'region' => 'eu-west',
        ],
    ],
],
```

### `default_strategy`

The routing strategy used to determine which shard receives a given key. Built-in options: `consistent_hash`, `range`, `modulo`. You may also provide a custom class implementing `ShardStrategyInterface`.

```php
'default_strategy' => env('SHARDWISE_STRATEGY', 'consistent_hash'),
```

### `consistent_hash`

Settings for the consistent hash routing strategy.

```php
'consistent_hash' => [
    // Number of virtual nodes per shard. Higher values improve key distribution
    // but use more memory. Recommended: 100-200.
    'virtual_nodes' => (int) env('SHARDWISE_VIRTUAL_NODES', 150),

    // Hash algorithm. Options: 'xxh128', 'xxh64', 'sha256', 'md5'.
    // xxh128 is recommended for best performance.
    'hash_algorithm' => env('SHARDWISE_HASH_ALGORITHM', 'xxh128'),
],
```

### `table_groups`

Groups of related tables that should always be routed to the same shard, preserving referential integrity.

```php
'table_groups' => [
    'users' => ['users', 'user_profiles', 'user_settings', 'user_tokens'],
    'orders' => ['orders', 'order_items', 'order_payments'],
],
```

### `uuid`

Configuration for shard-aware UUID generation. When metadata embedding is enabled, the shard ID can be extracted directly from the UUID, allowing routing without a database lookup.

```php
'uuid' => [
    // UUID version. Version 7 is recommended for time-sortability.
    'version' => (int) env('SHARDWISE_UUID_VERSION', 7),

    // Embed shard ID in the UUID so it can be decoded later.
    'embed_shard_metadata' => (bool) env('SHARDWISE_UUID_EMBED_METADATA', true),

    // Number of bits for the shard ID. 10 bits supports up to 1024 shards.
    'shard_bits' => (int) env('SHARDWISE_UUID_SHARD_BITS', 10),
],
```

### `bootstrappers`

Bootstrappers execute when entering and exiting a shard context. They handle setup and teardown of shard-specific resources like database connections and cache prefixes.

```php
'bootstrappers' => [
    \Skylence\Shardwise\Bootstrappers\DatabaseBootstrapper::class,
    \Skylence\Shardwise\Bootstrappers\CacheBootstrapper::class,
],
```

### `queue`

Settings for automatic shard context preservation in queued jobs.

```php
'queue' => [
    // Automatically inject shard context into job payloads.
    'aware' => (bool) env('SHARDWISE_QUEUE_AWARE', true),

    // The key used in job payloads to store the shard ID.
    'payload_key' => 'shardwise_shard_id',
],
```

### `connection_pool`

Database connection pooling configuration.

```php
'connection_pool' => [
    // Maximum number of connections per shard.
    'max_connections' => (int) env('SHARDWISE_MAX_CONNECTIONS', 10),

    // Idle connection timeout in seconds.
    'idle_timeout' => (int) env('SHARDWISE_IDLE_TIMEOUT', 60),
],
```

### `dead_shard_tolerance`

When enabled, cross-shard queries continue executing on remaining shards if one or more shards fail. Failed shards are silently skipped and partial results are returned.

```php
'dead_shard_tolerance' => (bool) env('SHARDWISE_DEAD_SHARD_TOLERANCE', false),
```

> **Warning:** Only enable this in production when partial results are acceptable. In development, you typically want failures to surface immediately.

### `health`

Shard health monitoring configuration.

```php
'health' => [
    // Timeout for health check queries in seconds.
    'timeout' => (int) env('SHARDWISE_HEALTH_TIMEOUT', 5),

    // Query executed to verify shard connectivity.
    'query' => 'SELECT 1',
],
```

### `migrations`

Settings for shard-specific migrations.

```php
'migrations' => [
    // Directory for shard migration files.
    'path' => database_path('migrations/shards'),

    // Table name for tracking applied shard migrations.
    'table' => 'shardwise_migrations',
],
```

---

## Models

### Sharded Models

Add the `Shardable` trait to any Eloquent model whose data is distributed across shards.

```php
use Illuminate\Database\Eloquent\Model;
use Skylence\Shardwise\Eloquent\Shardable;

class Order extends Model
{
    use Shardable;

    /**
     * The column used to determine which shard this record belongs to.
     * Defaults to the model's primary key if not specified.
     */
    protected string $shardKeyColumn = 'tenant_id';

    /**
     * Enable shard-aware UUID generation for this model.
     * The shard ID is embedded in the UUID so records can be routed
     * without a central lookup.
     */
    protected bool $shardAwareUuid = true;

    /**
     * Optional: assign this model to a table group so related
     * tables always route to the same shard.
     */
    protected string $tableGroup = 'orders';
}
```

The `Shardable` trait provides:

- `Order::onShard('shard-1')` -- query a specific shard
- `Order::onAllShards()` -- query across all active shards
- `Order::onCentral()` -- query the central connection explicitly
- Automatic shard-aware UUID generation on model creation (when `$shardAwareUuid = true`)
- Automatic connection resolution based on the current shard context

### Central Models

Models whose tables live in the central database (not sharded) must use the `CentralModel` trait.

```php
use Illuminate\Database\Eloquent\Model;
use Skylence\Shardwise\Eloquent\CentralModel;

class User extends Model
{
    use CentralModel;
}
```

**Why this is necessary:** When a shard context is active, Laravel's default database connection is switched to the shard connection. Without `CentralModel`, any model using the default connection would inadvertently query the shard database -- where its table does not exist. The `CentralModel` trait detects active shard contexts and pins the model to the central connection, while preserving normal behavior (including test environments) when no shard context is active.

### Cross-Shard Relationships

Central models can define relationships to sharded models using the `HasShardedRelationships` trait. Standard Eloquent `hasMany()` and `hasOne()` would only query the central database, missing all sharded records.

```php
use Illuminate\Database\Eloquent\Model;
use Skylence\Shardwise\Eloquent\CentralModel;
use Skylence\Shardwise\Eloquent\HasShardedRelationships;
use Skylence\Shardwise\Eloquent\Relations\CrossShardHasMany;
use Skylence\Shardwise\Eloquent\Relations\CrossShardHasOne;

class Agent extends Model
{
    use CentralModel, HasShardedRelationships;

    public function tickets(): CrossShardHasMany
    {
        return $this->hasManyAcrossShards(Ticket::class, 'agent_id');
    }

    public function activeSession(): CrossShardHasOne
    {
        return $this->hasOneAcrossShards(Session::class, 'agent_id');
    }
}
```

#### Cross-Shard Relationship API

Cross-shard relationships support a fluent query builder interface:

```php
// Get all related records across all shards
$agent->tickets()->get();

// Count across all shards
$agent->tickets()->count();

// Check existence (short-circuits on first match)
$agent->tickets()->exists();

// Filtering
$agent->tickets()->where('status', 'open')->get();
$agent->tickets()->whereIn('priority', ['high', 'critical'])->get();
$agent->tickets()->whereBetween('created_at', [$start, $end])->get();

// Ordering (applied globally after merging results from all shards)
$agent->tickets()->orderBy('created_at', 'desc')->limit(10)->get();
$agent->tickets()->latest()->first();

// Aggregations
$agent->tickets()->sum('hours_spent');
$agent->tickets()->min('response_time');
$agent->tickets()->max('response_time');

// Pluck values
$agent->tickets()->pluck('subject');

// Eager loading and column selection
$agent->tickets()->with('comments')->select('id', 'subject', 'status')->get();

// Create a related record (foreign key is set automatically)
$agent->tickets()->create(['subject' => 'New issue', 'status' => 'open']);
```

---

## Querying

### Single Shard

Target a specific shard for your query:

```php
Order::onShard('shard-1')->where('total', '>', 100)->get();
Order::onShard('shard-2')->find($orderId);
```

### All Shards

Query across every active shard. Results are merged, and aggregations are computed correctly:

```php
// Retrieve records from all shards
Order::onAllShards()->where('status', 'active')->get();

// Aggregations are correctly computed across shards
Order::onAllShards()->count();
Order::onAllShards()->avg('total');    // Correctly weighted average
Order::onAllShards()->sum('total');
Order::onAllShards()->min('total');
Order::onAllShards()->max('total');

// Global ordering across all shards
Order::onAllShards()->orderBy('created_at', 'desc')->get();

// Cross-shard pagination with correct offsets
Order::onAllShards()->paginate(15);
```

### Shard Context

Use the `shardwise()->run()` method to execute multiple operations within a single shard context. All queries inside the callback are routed to the specified shard:

```php
shardwise()->run('shard-1', function () {
    $order = Order::create([
        'tenant_id' => 42,
        'total' => 250,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product' => 'Widget',
        'quantity' => 5,
    ]);
});
```

Helper functions are also available:

```php
// Execute on a specific shard
on_shard('shard-1', function () {
    Order::where('status', 'pending')->update(['status' => 'processing']);
});

// Execute on all shards
on_all_shards(function ($shard) {
    return Order::where('status', 'stale')->count();
});
// Returns: ['shard-1' => 3, 'shard-2' => 7, 'shard-3' => 1]

// Get the current shard context
$currentShard = shard();

// Get a specific shard by ID
$shard = shard('shard-2');
```

### Central Database

Explicitly run queries on the central database, regardless of any active shard context:

```php
use Skylence\Shardwise\Central;

Central::run(function () {
    $activeUsers = User::where('active', true)->get();
    $settings = GlobalSetting::first();
});
```

---

## Queue Jobs

Use the `ShardAwareJob` trait to ensure jobs execute on the correct shard:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Skylence\Shardwise\Queue\ShardAwareJob;

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ShardAwareJob;

    public function __construct(
        public int $orderId,
    ) {}

    public function handle(): void
    {
        $this->executeInShardContext(function () {
            $order = Order::findOrFail($this->orderId);
            // Process the order on its shard...
        });
    }
}
```

Dispatch the job with a shard context:

```php
ProcessOrder::dispatch($orderId)->onShard('shard-1');
```

When `queue.aware` is enabled in the config (the default), the shard context is automatically serialized into the job payload and restored when the job is processed.

---

## Migrations

Shard migrations live in a separate directory from your central migrations and are applied to every shard.

### Creating a shard migration

```bash
php artisan shardwise:make-migration create_orders_table --create=orders
```

This creates a migration file in `database/migrations/shards/` (configurable via `migrations.path`).

### Running shard migrations

```bash
# Run migrations on all shards
php artisan shardwise:migrate

# Run migrations on a specific shard
php artisan shardwise:migrate --shard=shard-1

# Run migrations for a specific table group
php artisan shardwise:migrate --table-group=orders

# Rollback the last migration on all shards
php artisan shardwise:migrate --rollback

# Fresh migration (drop all tables and re-run)
php artisan shardwise:migrate --fresh

# Seed after migrating
php artisan shardwise:migrate --seed
```

### Other Artisan commands

```bash
# List all configured shards
php artisan shardwise:list

# Check migration status across shards
php artisan shardwise:status

# Run health checks on all shards
php artisan shardwise:health

# Seed shard databases
php artisan shardwise:seed
```

---

## Avoiding Common Sharding Pitfalls

### 1. Always use `CentralModel` on non-sharded models

Without `CentralModel`, queries hit the shard database during an active shard context -- where the central table does not exist. This causes "table not found" errors that are difficult to debug.

```php
// WRONG: This model will break inside a shard context
class User extends Model {}

// CORRECT: Pinned to central connection regardless of shard context
class User extends Model
{
    use CentralModel;
}
```

### 2. Never use standard `hasMany`/`belongsTo` across shard boundaries

Standard Eloquent relationships query a single database connection. They cannot reach records on other shards.

```php
// WRONG: Only queries the central database
public function tickets(): HasMany
{
    return $this->hasMany(Ticket::class);
}

// CORRECT: Queries all shards and merges results
public function tickets(): CrossShardHasMany
{
    return $this->hasManyAcrossShards(Ticket::class, 'agent_id');
}
```

### 3. Use `with()` carefully

Eager loading works across connections only when the related model explicitly declares its connection -- either via the `CentralModel` trait or an explicit `$connection` property. Without it, eager-loaded queries may be sent to the wrong database.

### 4. Cross-shard JOINs are impossible

You cannot JOIN tables that live on different database connections. This is a fundamental limitation of database sharding. Use application-level joins instead:

```php
// WRONG: Cannot join across shards
Order::join('users', 'orders.user_id', '=', 'users.id')->get();

// CORRECT: Query separately and combine in application code
$orders = Order::onShard('shard-1')->where('user_id', $userId)->get();
$user = User::find($userId);
```

### 5. Aggregations across shards

Never compute an average-of-averages manually. Each shard's average is weighted differently based on its row count. Use the built-in cross-shard aggregation instead:

```php
// WRONG: Average of averages is mathematically incorrect
$averages = on_all_shards(fn () => Order::avg('total'));
$result = array_sum($averages) / count($averages);

// CORRECT: Properly weighted cross-shard average
$result = Order::onAllShards()->avg('total');
```

### 6. Foreign keys cannot span shards

Database-level foreign key constraints only work within a single database. You cannot enforce a FK from a shard table to a central table (or vice versa) at the database level. Use application-level validation instead.

### 7. Transactions are per-shard

A database transaction on shard-1 cannot guarantee atomicity with shard-2. If you need to write to multiple shards, implement compensating transactions or eventual consistency patterns.

```php
// This transaction only covers shard-1
shardwise()->run('shard-1', function () {
    DB::beginTransaction();
    try {
        Order::create([...]);
        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        throw $e;
    }
});
```

### 8. Pagination on deep pages is expensive

Cross-shard pagination requires each shard to return `offset + limit` rows so results can be globally sorted and sliced. For example, page 100 with 15 items per page means each shard returns 1,515 rows. Consider cursor-based pagination for large datasets.

### 9. Dead shard handling

Enable `dead_shard_tolerance` in production so that a single shard outage does not bring down your entire application. Queries continue on healthy shards, and failed shards are silently skipped.

```php
// config/shardwise.php
'dead_shard_tolerance' => env('SHARDWISE_DEAD_SHARD_TOLERANCE', true),
```

> **Warning:** With dead shard tolerance enabled, cross-shard queries return partial results. Your application must handle the possibility of incomplete data.

---

## Testing

```bash
composer test
```

For testing shard-aware code in your application, Shardwise provides `ShardwiseFake` and `MockShard` utilities to simulate shard behavior without requiring multiple database connections.

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jonas Vanderhaegen](https://github.com/jonasvanderhaegen)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

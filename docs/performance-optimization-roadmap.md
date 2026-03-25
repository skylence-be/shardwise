# Performance Optimization Roadmap

This document outlines three architectural improvements that reduce application-level sharding overhead from 5-9x to near-native performance.

## Problem Statement

The current architecture queries shards **sequentially from PHP**, which means:

```
Current flow (6.9x overhead on 3 shards):

  App  ──query──>  Shard 1  ──result──>  App  (2ms)
  App  ──query──>  Shard 2  ──result──>  App  (2ms)
  App  ──query──>  Shard 3  ──result──>  App  (2ms)
  App  ──merge/sort/paginate──>  Response  (0.5ms)
  Total: ~6.5ms  vs  Single DB: ~1ms
```

Three independent optimizations can be applied together:

---

## Optimization 1: Parallel Shard Queries

**Impact: Reduces N-shard serial queries to ~1 round-trip time**
**Expected improvement: 6.9x overhead → ~2.3x overhead**

### Before (sequential)
```
shard-1: 2ms ─┐
              │ serial
shard-2: 2ms ─┤
              │
shard-3: 2ms ─┘
Total: 6ms + merge
```

### After (parallel)
```
shard-1: 2ms ─┐
shard-2: 2ms ─┤ concurrent
shard-3: 2ms ─┘
Total: 2ms + merge
```

### Implementation

Use Laravel's `Concurrency` facade (Laravel 11+) with the `fork` driver for CLI contexts and `process` driver for web requests:

```php
use Illuminate\Support\Facades\Concurrency;

// In ShardableBuilder::getFromAllShards()
$shardCallbacks = [];
foreach ($shards as $shard) {
    $shardCallbacks[$shard->getId()] = fn () => $this->queryOnShard($shard, $columns);
}

$results = Concurrency::run($shardCallbacks);
$merged = collect($results)->flatten(1);
```

For even better performance in long-running processes (Octane, queue workers), use `amphp/parallel` which uses Fibers for true non-blocking I/O without process spawning overhead.

### Affected files
- `src/Eloquent/ShardableBuilder.php` — `getFromAllShards()`, `countFromAllShards()`
- `src/Query/CrossShardBuilder.php` — `executeOnShards()`
- `src/Query/CrossShardPaginator.php` — `fetchFromAllShards()`

### Configuration
```php
// config/shardwise.php
'parallel_queries' => [
    'enabled' => env('SHARDWISE_PARALLEL', true),
    'driver' => env('SHARDWISE_PARALLEL_DRIVER', 'concurrency'), // 'concurrency', 'amphp', 'sequential'
    'max_concurrent' => env('SHARDWISE_MAX_CONCURRENT', 10),
],
```

---

## Optimization 2: PostgreSQL Foreign Data Wrapper (postgres_fdw)

**Impact: Eliminates PHP-level query routing entirely for supported operations**
**Expected improvement: Near-zero overhead for queries PostgreSQL can push down**

### Architecture change

Instead of PHP querying N databases and merging results, configure a **coordinator PostgreSQL** that queries shard databases via `postgres_fdw`:

```
Current:
  App ──N queries──> N Shard DBs ──N results──> App (merge in PHP)

With postgres_fdw:
  App ──1 query──> Coordinator DB ──pushdown──> N Shard DBs
                   Coordinator DB <──results──  N Shard DBs
  App <──1 result── Coordinator DB (merge in PostgreSQL)
```

### How it works

1. **Coordinator database** has foreign tables pointing to each shard's tables via `postgres_fdw`
2. **Partitioned view** (or inheritance) combines all shards into one logical table
3. **Application queries the coordinator** as if it were a single database
4. **PostgreSQL's query planner** handles pushdown, aggregation, and sorting at the database level

### Setup

```sql
-- On the coordinator database
CREATE EXTENSION postgres_fdw;

-- Create server connections to each shard
CREATE SERVER shard_1 FOREIGN DATA WRAPPER postgres_fdw
    OPTIONS (host '127.0.0.1', port '5432', dbname 'shard1');
CREATE SERVER shard_2 FOREIGN DATA WRAPPER postgres_fdw
    OPTIONS (host '127.0.0.1', port '5432', dbname 'shard2');
CREATE SERVER shard_3 FOREIGN DATA WRAPPER postgres_fdw
    OPTIONS (host '127.0.0.1', port '5432', dbname 'shard3');

-- Create user mappings
CREATE USER MAPPING FOR app_user SERVER shard_1 OPTIONS (user 'app', password 'secret');
CREATE USER MAPPING FOR app_user SERVER shard_2 OPTIONS (user 'app', password 'secret');
CREATE USER MAPPING FOR app_user SERVER shard_3 OPTIONS (user 'app', password 'secret');

-- Create foreign tables
CREATE FOREIGN TABLE shard1_projects (LIKE projects)
    SERVER shard_1 OPTIONS (table_name 'projects');
CREATE FOREIGN TABLE shard2_projects (LIKE projects)
    SERVER shard_2 OPTIONS (table_name 'projects');
CREATE FOREIGN TABLE shard3_projects (LIKE projects)
    SERVER shard_3 OPTIONS (table_name 'projects');

-- Create a unified view
CREATE VIEW all_projects AS
    SELECT *, 'shard-1' AS _shard FROM shard1_projects
    UNION ALL
    SELECT *, 'shard-2' AS _shard FROM shard2_projects
    UNION ALL
    SELECT *, 'shard-3' AS _shard FROM shard3_projects;
```

### Query pushdown

PostgreSQL automatically pushes down WHERE clauses, aggregates, and sorts to the remote shards:

```sql
-- This gets pushed down to each shard individually
SELECT count(*) FROM all_projects WHERE status = 'active';
-- PostgreSQL sends: SELECT count(*) FROM projects WHERE status = 'active'
-- to each shard, then sums the results

-- Aggregates are pushed down too
SELECT avg(budget) FROM all_projects;
-- Each shard computes its own sum and count, coordinator combines
```

### Performance parameters

```sql
-- Optimize FDW performance
ALTER SERVER shard_1 OPTIONS (ADD fetch_size '10000');
ALTER SERVER shard_1 OPTIONS (ADD use_remote_estimate 'true');
ALTER SERVER shard_1 OPTIONS (ADD async_capable 'true');  -- PostgreSQL 14+
```

The `async_capable` option enables PostgreSQL to query multiple foreign servers **in parallel** at the database level — achieving the same parallelism as Optimization 1 but without PHP involvement.

### Package integration

The package provides a `FdwBootstrapper` that:
1. Detects if postgres_fdw is configured
2. Routes `onAllShards()` queries to the coordinator's unified views instead of querying each shard from PHP
3. Falls back to sequential PHP queries if FDW is not available

```php
// config/shardwise.php
'fdw' => [
    'enabled' => env('SHARDWISE_FDW_ENABLED', false),
    'coordinator_connection' => env('SHARDWISE_FDW_CONNECTION', 'coordinator'),
    'view_prefix' => 'all_',  // all_projects, all_tasks, etc.
],
```

### Limitations

- **PostgreSQL only** — this optimization is not available for MySQL
- **Write operations** still go directly to shard connections (FDW views are read-only by default)
- **Complex Eloquent operations** (eager loading with closures, scopes) may not push down efficiently
- **Setup overhead** — requires creating foreign tables and views for every sharded table

---

## Optimization 3: Co-location Aware Routing

**Impact: Most queries hit exactly 1 shard instead of all N shards**
**Expected improvement: Eliminates cross-shard overhead entirely for co-located queries**

### The principle

If related data lives on the same shard, queries never need to cross shard boundaries:

```
Before (naive routing):
  Team 5's projects → query ALL 3 shards → merge

After (co-location):
  Team 5's projects → route to shard-2 only → done
```

### How Citus does it

Citus co-locates tables by distribution column: all rows with `team_id = 5` across ALL tables (projects, tasks, documents, invoices) land on the same shard. This means:

- `Project::where('team_id', 5)->with('tasks')->get()` hits **one shard**
- `Task::where('project_id', $id)->get()` resolves to **one shard** via the project's shard key
- JOINs between co-located tables work because they're on the same database

### Implementation

The package already has `shardKeyColumn` on models. We enhance routing to:

1. **Auto-detect shard from query constraints**: If a query has `WHERE team_id = X`, route to that team's shard instead of querying all shards
2. **Resolve shard from related model**: If loading `$project->tasks`, use the project's shard (not all shards)
3. **UUID shard extraction**: Extract shard from shard-aware UUIDs to route `find()` calls

```php
// In ShardableBuilder::get()
public function get($columns = ['*']): Collection
{
    if ($this->allShards) {
        // Check if we can narrow down to a single shard from WHERE clauses
        $targetShard = $this->resolveShardFromConstraints();
        if ($targetShard !== null) {
            return $this->onShard($targetShard)->get($columns);
        }
        return $this->getFromAllShards($columns);
    }
    return parent::get($columns);
}

private function resolveShardFromConstraints(): ?ShardInterface
{
    $shardKeyColumn = $this->model->getShardKeyColumn();

    foreach ($this->getQuery()->wheres as $where) {
        if (($where['column'] ?? null) === $shardKeyColumn && ($where['type'] ?? null) === 'Basic') {
            return shardwise()->route($where['value']);
        }
    }

    return null;
}
```

### Configuration

```php
// config/shardwise.php
'co_location' => [
    'enabled' => env('SHARDWISE_COLOCATION', true),
    'auto_detect' => true,  // Scan WHERE clauses for shard key
],
```

### Impact on our benchmark

With co-location enabled and typical multi-tenant queries:

| Query | Current (all shards) | With co-location | Improvement |
|-------|:-------------------:|:----------------:|:-----------:|
| `Project::where('team_id', 5)->get()` | 0.69 ms (3 shards) | 0.10 ms (1 shard) | 6.9x faster |
| `Team->projects->tasks count` | 144 ms (3 shards) | ~48 ms (1 shard) | 3x faster |
| `Ticket::where('agent_id', X)->get()` | 0.38 ms (3 shards) | 0.38 ms (still all) | No change (agent_id is not the shard key) |

Co-location only helps when the query contains the shard key. Global queries (counts, dashboards) still need all shards.

---

## Combined Impact

| Optimization | Simple count overhead | Complex query overhead |
|-------------|:--------------------:|:---------------------:|
| Current (sequential PHP) | 6.9x | 0.75x (sharded wins) |
| + Parallel queries | ~2.3x | ~0.25x |
| + Co-location routing | 1.0x (routed queries) | ~0.25x |
| + postgres_fdw | ~1.1x | ~0.3x |
| All three combined | **~1.0-1.1x** | **~0.2-0.3x** |

With all three optimizations, the overhead for routed queries drops to near-zero, and cross-shard queries approach database-native performance.

---

## PostgreSQL-Only Decision

Implementing postgres_fdw support means the package becomes **PostgreSQL-only**. This is the right trade-off because:

1. PostgreSQL is the only major RDBMS with built-in foreign data wrapper support
2. Citus (the gold standard for PostgreSQL sharding) is PostgreSQL-only
3. MySQL sharding is better served by Vitess (proxy-level, not application-level)
4. The async FDW queries (`async_capable`) are PostgreSQL 14+ only
5. PostgreSQL's query planner is significantly more capable of pushdown optimization

The package should declare `pgsql` as a requirement and document this clearly.

---

## Implementation Status

| Optimization | Status | Result |
|---|---|---|
| **Co-location routing** | Implemented, enabled by default | Keyed queries: 0.08ms (matches single DB's 0.07ms) |
| **Parallel queries (Laravel Concurrency)** | Implemented, opt-in | **Not viable** — process spawn overhead (~60ms) destroys sub-ms query gains |
| **postgres_fdw** | Artisan commands implemented | Setup tooling ready, needs production testing |

### Parallel Queries: Benchmark Findings

Laravel's `Concurrency` facade forks a new PHP process per shard query. With 3 shards:

| Metric | Sequential | Parallel (Concurrency) |
|--------|:----------:|:---------------------:|
| Project::count() | 0.61 ms | 58.17 ms |
| Dashboard (5 counts) | 3.50 ms | 200.36 ms |

The ~60ms process spawning overhead per fork makes this **93x slower** for sub-millisecond queries. Laravel Concurrency is designed for slow operations (API calls >200ms), not fast DB queries.

### Future Option: AmPHP Worker Pools

For parallel queries to be viable, the package would need [`amphp/parallel`](https://packagist.org/packages/amphp/parallel) with **persistent worker pools** and [`amphp/postgres`](https://amphp.org/postgres) for async non-blocking I/O:

| | Laravel Concurrency | AmPHP Parallel |
|---|---|---|
| How it works | Forks new PHP process per call | Pre-spawned worker pool, reuses processes |
| Startup cost | ~60ms per fork | ~0ms (workers already running) |
| DB connections | Each process opens its own | Persistent connection pool |
| Best for | Slow tasks (API calls, >200ms) | Fast concurrent I/O (DB queries, <10ms) |

With `amphp/postgres`, 3 shard queries would run simultaneously on a **single thread** using PHP 8.1+ Fibers — no process spawning, no serialization overhead. Expected result: ~0.2ms for a 3-shard count (matching single-DB performance).

**Why it's not implemented yet:**
- Replaces PDO with amphp's own connection layer — significant architectural change
- Requires running an event loop, which conflicts with Laravel's synchronous request lifecycle
- Would work best with Laravel Octane (Swoole/FrankenPHP) where the event loop persists between requests
- Testing complexity increases significantly

This remains the most promising path to eliminating cross-shard overhead entirely while keeping application-level sharding.

## References

- [Laravel 13 Concurrency Facade](https://laravel.com/docs/13.x/concurrency)
- [AmPHP parallel library](https://github.com/amphp/parallel)
- [PostgreSQL postgres_fdw documentation](https://www.postgresql.org/docs/current/postgres-fdw.html)
- [Mastering postgres_fdw (Microsoft)](https://techcommunity.microsoft.com/blog/adforpostgresql/mastering-postgres-fdw-setup-optimize-performance-and-avoid-common-pitfalls/4463564)
- [FDW query pushdown (freeCodeCamp)](https://www.freecodecamp.org/news/fdw-pushdown/)
- [How Citus works — query pushdown and co-location](https://www.citusdata.com/blog/2017/09/15/how-citus-works/)
- [Citus table co-location](https://docs.citusdata.com/en/v7.2/sharding/colocation.html)
- [PostgreSQL built-in sharding roadmap](https://wiki.postgresql.org/wiki/Built-in_Sharding)

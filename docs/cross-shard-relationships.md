# Cross-Shard Relationships

Sharding splits data across multiple databases. Eloquent relationships assume all data lives on a single connection, so relationships that span shard boundaries require special handling. This guide explains the problem and shows how Shardwise solves it.

## The Problem

Standard Eloquent relationships break in two directions when sharding is involved:

### Sharded -> Central (belongsTo)

A `Ticket` model lives on a shard database. It has a `belongsTo(Agent::class)` relationship, but the `agents` table only exists on the central database. When a shard context is active, Eloquent tries to query the `agents` table on the shard connection — and fails because the table does not exist there.

### Central -> Sharded (hasMany)

An `Agent` model lives on the central database. It has a `hasMany(Ticket::class)` relationship, but tickets are distributed across multiple shards. A standard `hasMany()` only queries the central database connection, where no `tickets` table exists.

---

## Solution 1: Sharded Model -> Central Model (belongsTo)

The `CentralModel` trait solves this direction. When applied to a model, it overrides `getConnectionName()` to always return the central database connection whenever a shard context is active. This means Eloquent's eager loading system detects the fixed connection and queries the central DB automatically.

No special relationship methods are needed — just add the `CentralModel` trait to any model that lives on the central database.

```php
use Illuminate\Database\Eloquent\Model;
use Skylence\Shardwise\Eloquent\CentralModel;
use Skylence\Shardwise\Eloquent\Shardable;

// Agent model — lives on the central database
class Agent extends Model
{
    use CentralModel;
}

// Ticket model — lives on shard databases
class Ticket extends Model
{
    use Shardable;

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
```

With this setup, cross-shard eager loading works correctly:

```php
// Queries each shard for tickets, then queries the central DB for agents
Ticket::onAllShards()->with('agent')->get();

// Single-shard queries also work
Ticket::onShard('shard-1')->with('agent')->get();
```

### How It Works Under the Hood

The `ShardableBuilder` overrides `eagerLoadRelation()`. When it detects that the related model has a non-null connection (set by `CentralModel`), it swaps the relation query's connection to point at the central database instead of the current shard. This happens transparently for all standard Eloquent relationship types (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, etc.) as long as the related model uses `CentralModel`.

---

## Solution 2: Central Model -> Sharded Model (hasMany / hasOne)

Use the `HasShardedRelationships` trait on the central model. It provides two methods:

- `hasManyAcrossShards()` — returns a `CrossShardHasMany` relation
- `hasOneAcrossShards()` — returns a `CrossShardHasOne` relation

These are not standard Eloquent `Relation` objects. They are purpose-built query objects that iterate over all active shards, collect results, and merge them.

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

### Querying CrossShardHasMany

The `CrossShardHasMany` relation supports a fluent API for filtering, ordering, limiting, and aggregating:

```php
// Get all tickets for an agent (from all shards)
$agent->tickets()->get();

// Filter with where clauses
$agent->tickets()->where('status', 'open')->get();
$agent->tickets()->whereIn('priority', ['high', 'critical'])->get();
$agent->tickets()->whereBetween('created_at', [$start, $end])->get();
$agent->tickets()->whereNull('resolved_at')->get();

// Ordering (applied globally after merging shard results)
$agent->tickets()->orderBy('created_at', 'desc')->get();
$agent->tickets()->latest()->get();
$agent->tickets()->oldest()->get();

// Limiting
$agent->tickets()->orderBy('created_at', 'desc')->limit(10)->get();
$agent->tickets()->latest()->take(5)->get();

// Offset and limit (for manual pagination)
$agent->tickets()->orderBy('created_at', 'desc')->offset(20)->limit(10)->get();

// Aggregations
$agent->tickets()->count();
$agent->tickets()->where('status', 'open')->count();
$agent->tickets()->sum('billable_hours');
$agent->tickets()->min('created_at');
$agent->tickets()->max('priority_score');

// Existence check (short-circuits on first match)
$agent->tickets()->where('status', 'open')->exists();

// First record
$agent->tickets()->orderBy('created_at', 'desc')->first();

// Pluck values
$agent->tickets()->pluck('subject');
$agent->tickets()->pluck('subject', 'id');

// Eager loading on the results
$agent->tickets()->with('comments', 'tags')->get();

// Select specific columns
$agent->tickets()->select('id', 'subject', 'status')->get();

// Create a new ticket (foreign key is set automatically)
$agent->tickets()->create([
    'subject' => 'New Issue',
    'status' => 'open',
]);
```

### Querying CrossShardHasOne

The `CrossShardHasOne` relation short-circuits as soon as the related model is found on any shard:

```php
// Get the active session (stops checking shards once found)
$agent->activeSession()->get();

// Check existence
$agent->activeSession()->exists();

// With constraints
$agent->activeSession()->where('active', true)->get();
```

---

## What You Cannot Do

Sharding introduces hard boundaries. The following operations are **not supported** across shard boundaries:

### No Cross-Shard JOINs

Database JOINs only work within a single database connection. You cannot JOIN a table on shard-1 with a table on shard-2, or with a table on the central database.

```php
// This will NOT work — the agents table does not exist on the shard
Ticket::onShard('shard-1')
    ->join('agents', 'tickets.agent_id', '=', 'agents.id')
    ->get();
```

**Workaround**: Load data separately and merge in application code.

### No `whereHas()` Across Shard Boundaries

Since `whereHas()` compiles to a subquery, and the subquery runs on the same connection as the parent query, it cannot reach across to another database.

```php
// This will NOT work across shards
Agent::whereHas('tickets', function ($query) {
    $query->where('status', 'open');
})->get();
```

**Workaround**: Query the sharded model first, collect the foreign keys, then filter the central model:

```php
$agentIds = Ticket::onAllShards()
    ->where('status', 'open')
    ->pluck('agent_id')
    ->unique();

$agents = Agent::whereIn('id', $agentIds)->get();
```

### No `sync()` / `attach()` for Cross-Shard Many-to-Many

Pivot tables must live on one connection. If both sides of a many-to-many relationship are on different connections, `sync()` and `attach()` cannot work.

### No Database-Level Foreign Key Constraints

Foreign key constraints are enforced at the database level and only work within a single database. You cannot create a FK from a shard table pointing to a central table or vice versa.

**Workaround**: Enforce referential integrity in application code (model observers, form request validation).

### No Cross-Shard Transactions

Each shard has its own transaction boundary. A `DB::transaction()` on one shard does not include operations on another shard.

---

## Decision Matrix

Use this table to determine which approach to use for each relationship scenario:

| Parent Model | Related Model | Relationship Method | Trait(s) Required | Notes |
|---|---|---|---|---|
| Sharded | Central | `belongsTo()` (standard) | Related uses `CentralModel` | Works automatically via eager load connection detection |
| Sharded | Central | `hasOne()` / `hasMany()` (standard) | Related uses `CentralModel` | Same mechanism — related model pins to central connection |
| Central | Sharded | `hasManyAcrossShards()` | Parent uses `HasShardedRelationships` | Queries all active shards and merges results |
| Central | Sharded | `hasOneAcrossShards()` | Parent uses `HasShardedRelationships` | Short-circuits on first shard with a match |
| Sharded | Sharded (same shard key) | `hasMany()` / `belongsTo()` (standard) | None beyond `Shardable` | Works within the same shard context — use table groups |
| Sharded | Sharded (different shard key) | **Not supported** | N/A | Redesign your schema so related data shares a shard key |
| Central | Central | `hasMany()` / `belongsTo()` (standard) | Both use `CentralModel` | No sharding involved — standard Eloquent behavior |

---

## Best Practices

1. **Always add `CentralModel` to non-sharded models.** If a model's table lives only on the central database, it must use `CentralModel` to avoid connection issues when a shard context is active.

2. **Use table groups for related sharded data.** Configure `table_groups` in `config/shardwise.php` so that related tables (e.g., `orders` and `order_items`) are always routed to the same shard.

3. **Prefer querying the sharded side first.** When you need to filter a central model by sharded data, query the sharded model to collect IDs, then use `whereIn()` on the central model.

4. **Be mindful of N+1 across shards.** Each `CrossShardHasMany` call queries all active shards. If you loop over 100 agents calling `$agent->tickets()->get()`, you make `100 x N` database queries (where N is the number of shards). Collect all agent IDs and batch the query instead.

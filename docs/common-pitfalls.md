# Common Sharding Pitfalls & How to Avoid Them

This guide covers the most frequent mistakes developers make when working with sharded databases in Shardwise, along with clear fixes and explanations for each.

---

## Pitfall 1: Forgetting CentralModel on Non-Sharded Models

**Symptom**: "Undefined table" or "Base table or view not found" errors when a shard context is active and you query a model that lives on the central database.

**Why it happens**: When a shard context is active, the default database connection is switched to the shard connection. Models without `CentralModel` use this default connection, so they try to find their table on the shard database — where it does not exist.

**Rule**: If a model's table exists only on the central database, it MUST use `CentralModel`.

```php
// DON'T: Model without CentralModel will query the shard DB
class Agent extends Model
{
    // No CentralModel trait — queries will fail when shard context is active
}
```

```php
// DO: Pin the model to the central database connection
use Skylence\Shardwise\Eloquent\CentralModel;

class Agent extends Model
{
    use CentralModel;
}
```

---

## Pitfall 2: Using Standard hasMany for Cross-Shard Relationships

**Symptom**: `$agent->tickets` returns an empty collection or throws a "table not found" error because the `tickets` table does not exist on the central database.

**Why it happens**: A standard `hasMany()` queries the same connection as the parent model. If the parent model is on the central database and the related model is on shard databases, the query runs against the wrong database.

**Rule**: Any relationship from a central model to a sharded model must use `hasManyAcrossShards()` or `hasOneAcrossShards()`.

```php
// DON'T: Standard hasMany only queries the central DB
class Agent extends Model
{
    use CentralModel;

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
```

```php
// DO: Use cross-shard relationship methods
use Skylence\Shardwise\Eloquent\CentralModel;
use Skylence\Shardwise\Eloquent\HasShardedRelationships;
use Skylence\Shardwise\Eloquent\Relations\CrossShardHasMany;

class Agent extends Model
{
    use CentralModel, HasShardedRelationships;

    public function tickets(): CrossShardHasMany
    {
        return $this->hasManyAcrossShards(Ticket::class, 'agent_id');
    }
}
```

---

## Pitfall 3: Expecting Cross-Shard JOINs to Work

**Symptom**: "Table not found" errors in JOIN queries, or incorrect results because the joined table exists on a different database connection.

**Why it happens**: SQL JOINs operate within a single database connection. You cannot JOIN tables across different database servers or connections.

**Rule**: Database JOINs only work within a single connection. Load data separately and merge in application code.

```php
// DON'T: JOIN across shard and central databases
$results = Ticket::onShard('shard-1')
    ->join('agents', 'tickets.agent_id', '=', 'agents.id')
    ->select('tickets.*', 'agents.name')
    ->get();
```

```php
// DO: Load separately and combine
$tickets = Ticket::onShard('shard-1')
    ->where('status', 'open')
    ->get();

$agentIds = $tickets->pluck('agent_id')->unique();
$agents = Agent::whereIn('id', $agentIds)->get()->keyBy('id');

$tickets->each(function (Ticket $ticket) use ($agents) {
    $ticket->agentName = $agents->get($ticket->agent_id)?->name;
});
```

---

## Pitfall 4: Computing Averages Manually Instead of Using the Package

**Symptom**: Incorrect average values when shard sizes differ. The error is subtle and can go unnoticed if data is approximately evenly distributed.

**Why it happens**: Averaging per-shard averages gives equal weight to each shard, regardless of how many records each shard holds. This produces a mathematically incorrect result.

**Rule**: Never compute avg-of-averages across shards manually. Use `Model::onAllShards()->avg('column')`.

```php
// DON'T: Average of averages is mathematically wrong
$shardAvgs = [];
foreach (shardwise()->getShards()->active() as $shard) {
    $shardAvgs[] = shardwise()->run($shard, fn () => Order::avg('total'));
}
$average = array_sum($shardAvgs) / count($shardAvgs); // WRONG
```

```php
// DO: Use Shardwise's weighted average
$average = Order::onAllShards()->avg('total'); // Correct weighted average
```

See the [Aggregations guide](./aggregations.md) for details on how Shardwise computes this correctly using `SUM / COUNT` across all shards.

---

## Pitfall 5: Missing Shard Context for Sharded Operations

**Symptom**: Queries hit the central database for tables that only exist on shard databases, resulting in "table not found" errors or silently querying the wrong database.

**Why it happens**: A model with the `Shardable` trait does not automatically know which shard to query. Without an explicit shard context (`onShard()`, `onAllShards()`, or `shardwise()->run()`), the query falls back to the default connection.

**Rule**: Sharded models MUST have explicit shard context for queries.

```php
// DON'T: No shard context — queries the default (central) connection
$tickets = Ticket::where('status', 'open')->get();
```

```php
// DO: Provide explicit shard context
$tickets = Ticket::onShard('shard-1')
    ->where('status', 'open')
    ->get();

// Or query all shards
$tickets = Ticket::onAllShards()
    ->where('status', 'open')
    ->get();

// Or use the shardwise manager
$tickets = shardwise()->run($shard, function () {
    return Ticket::where('status', 'open')->get();
});
```

---

## Pitfall 6: Relying on Auto-Increment IDs Across Shards

**Symptom**: Duplicate primary key values across shards. Two different tickets on two different shards both have `id = 1`, `id = 2`, etc. This causes confusion when referencing records and breaks any logic that assumes IDs are globally unique.

**Why it happens**: Each shard database has its own auto-increment sequence. They are independent and will produce the same values.

**Rule**: Always use UUIDs for sharded models. Shardwise provides shard-aware UUIDs that embed the shard ID for efficient routing.

```php
// DON'T: Auto-increment IDs are not unique across shards
class Ticket extends Model
{
    use Shardable;

    // Default auto-increment ID — will collide across shards
}
```

```php
// DO: Use shard-aware UUIDs
class Ticket extends Model
{
    use Shardable;

    public $incrementing = false;
    protected $keyType = 'string';
    protected bool $shardAwareUuid = true;
}
```

The `$shardAwareUuid = true` property tells Shardwise to automatically generate a UUID with embedded shard metadata when creating new records. This UUID can later be decoded to determine which shard holds the record without querying all shards.

---

## Pitfall 7: Assuming Transactions Span Shards

**Symptom**: Partial data written when one shard fails mid-operation. For example, you create an order on shard-1 and order items on shard-2 in what you think is a single transaction, but shard-2 fails and the order exists without items.

**Why it happens**: Each shard has its own independent transaction boundary. `DB::transaction()` only applies to one database connection. There is no distributed transaction coordinator.

**Rule**: Each shard has its own transaction boundary. Use the saga pattern or compensating transactions for multi-shard writes.

```php
// DON'T: This is NOT a cross-shard transaction
DB::transaction(function () {
    // This transaction only applies to ONE connection
    $order = Order::onShard('shard-1')->create([...]);
    $item = OrderItem::onShard('shard-2')->create([...]); // Different connection!
});
```

```php
// DO: Use table groups so related data lives on the same shard
// config/shardwise.php
'table_groups' => [
    'orders' => ['orders', 'order_items', 'order_payments'],
],

// Now orders and order_items are always on the same shard
shardwise()->run($shard, function () {
    DB::transaction(function () {
        $order = Order::create([...]);
        OrderItem::create(['order_id' => $order->id, ...]);
        // Both on the same shard — real transaction
    });
});
```

---

## Pitfall 8: Deep Pagination Performance

**Symptom**: Slow page loads for high page numbers. Page 1 loads in 50ms, page 100 takes 2 seconds, page 1,000 takes 30 seconds or times out.

**Why it happens**: Cross-shard pagination fetches `(page * perPage)` rows from each shard, merges and sorts them in memory, then discards all but `perPage` rows. The work scales linearly with page depth.

**Rule**: Cross-shard pagination performance degrades linearly with page number. Use cursor-based pagination for deep pages.

```php
// DON'T: Deep offset-based pagination
$tickets = Ticket::onAllShards()
    ->orderBy('created_at', 'desc')
    ->paginate(perPage: 15, page: 500);
// Fetches 7,500 rows per shard, 22,500 total for 3 shards
```

```php
// DO: Use cursor-based pagination
use Skylence\Shardwise\Query\CrossShardPaginator;

$paginator = new CrossShardPaginator(Ticket::query());
$results = $paginator->cursorPaginate(
    perPage: 15,
    cursorColumn: 'created_at',
    direction: 'desc',
    cursor: $lastSeenValue,
);
// Fetches only 15 rows per shard regardless of depth
```

See the [Pagination & Ordering guide](./pagination-and-ordering.md) for detailed performance characteristics.

---

## Pitfall 9: Not Handling Dead Shards in Production

**Symptom**: The entire application fails when one shard database becomes unavailable. Users see 500 errors for any page that queries across shards, even though the majority of shards are healthy.

**Why it happens**: By default, cross-shard operations throw an exception if any shard fails. This is the safe default for development but causes cascading failures in production.

**Rule**: Production applications should enable `dead_shard_tolerance` for read paths to gracefully degrade.

```php
// DON'T: Leave dead shard tolerance disabled in production
// config/shardwise.php
'dead_shard_tolerance' => false, // One dead shard = entire app fails
```

```php
// DO: Enable tolerance for production, disable for development
// config/shardwise.php
'dead_shard_tolerance' => (bool) env('SHARDWISE_DEAD_SHARD_TOLERANCE', false),

// .env (production)
SHARDWISE_DEAD_SHARD_TOLERANCE=true

// .env (development)
SHARDWISE_DEAD_SHARD_TOLERANCE=false
```

Combine this with health monitoring to know when shards are down:

```bash
php artisan shardwise:health
```

See the [Dead Shard Tolerance guide](./dead-shard-tolerance.md) for detailed configuration and monitoring strategies.

---

## Pitfall 10: Putting Query Logic in Blade Views

**Symptom**: Queries executed in Blade views hit the wrong database connection because the shard context is not active in the view rendering phase. Results are empty, incorrect, or throw exceptions.

**Why it happens**: Shard context is typically set in controllers or middleware. By the time the view renders, the context may have changed or been cleared. Views that instantiate models or run queries directly bypass the shard context setup.

**Rule**: Views should never run model queries directly. Move all queries to controllers and pass data to views.

```php
// DON'T: Query models in Blade views
// resources/views/dashboard.blade.php
@foreach(Ticket::where('status', 'open')->get() as $ticket)
    <li>{{ $ticket->subject }}</li>
@endforeach
```

```php
// DO: Query in the controller, pass to the view
// app/Http/Controllers/DashboardController.php
class DashboardController extends Controller
{
    public function index(): View
    {
        $tickets = Ticket::onAllShards()
            ->where('status', 'open')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('dashboard', compact('tickets'));
    }
}

// resources/views/dashboard.blade.php
@foreach($tickets as $ticket)
    <li>{{ $ticket->subject }}</li>
@endforeach
```

---

## Quick Reference

| # | Pitfall | Fix | Severity |
|---|---------|-----|----------|
| 1 | Missing `CentralModel` | Add `use CentralModel` to non-sharded models | High |
| 2 | Standard `hasMany` for cross-shard | Use `hasManyAcrossShards()` | High |
| 3 | Cross-shard JOINs | Load separately, merge in PHP | High |
| 4 | Manual avg-of-averages | Use `onAllShards()->avg()` | Medium |
| 5 | Missing shard context | Always use `onShard()` or `onAllShards()` | High |
| 6 | Auto-increment IDs | Use `$shardAwareUuid = true` | High |
| 7 | Assuming cross-shard transactions | Use table groups, saga pattern | High |
| 8 | Deep offset pagination | Use cursor-based pagination | Medium |
| 9 | No dead shard handling | Enable `dead_shard_tolerance` in production | Medium |
| 10 | Queries in Blade views | Move queries to controllers | Low |

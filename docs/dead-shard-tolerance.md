# Dead Shard Tolerance & High Availability

In a sharded architecture, individual shard databases can become unavailable due to hardware failures, network issues, maintenance windows, or resource exhaustion. By default, if any shard fails during a cross-shard operation, the entire operation throws an exception. Dead shard tolerance changes this behavior to gracefully degrade instead of failing completely.

## The Problem

Without dead shard tolerance, a single unhealthy shard takes down all cross-shard operations:

```php
// If shard-2 is down, this throws a connection exception
// even though shard-1 and shard-3 are perfectly healthy
$tickets = Ticket::onAllShards()->get(); // Exception!

// Same for aggregations
$count = Ticket::onAllShards()->count(); // Exception!

// Same for cross-shard relationships
$agent->tickets()->get(); // Exception!
```

In production, this means a single database server failure can make your entire application unusable for features that query across shards — even if 90% of your data is still accessible.

---

## Enabling Tolerance

### Via Configuration

```php
// config/shardwise.php
'dead_shard_tolerance' => true,
```

### Via Environment Variable

```env
SHARDWISE_DEAD_SHARD_TOLERANCE=true
```

---

## Behavior When Enabled

When dead shard tolerance is active, any shard that throws an exception during a cross-shard operation is silently skipped. The operation continues with the remaining healthy shards.

### Queries

```php
// With 3 shards and shard-2 down:
$tickets = Ticket::onAllShards()->get();
// Returns tickets from shard-1 and shard-3 only
// No exception thrown
```

### Aggregations

```php
// Returns count from healthy shards only
$count = Ticket::onAllShards()->count();
// If total is normally 12,000 (4,000 per shard),
// returns ~8,000 when one shard is down

$avg = Ticket::onAllShards()->avg('response_time');
// Weighted average computed from healthy shards only
```

### Cross-Shard Relationships

```php
// CrossShardHasMany skips unavailable shards
$agent->tickets()->get();
// Returns tickets from healthy shards only

$agent->tickets()->count();
// Returns partial count

$agent->tickets()->exists();
// Returns true if found on any healthy shard
```

### Pagination

```php
Ticket::onAllShards()
    ->orderBy('created_at', 'desc')
    ->paginate(15);
// Total count reflects only healthy shards
// Results come from healthy shards only
```

---

## When to Use Dead Shard Tolerance

### Enable for Read Operations

Dead shard tolerance is appropriate for read paths where partial results are acceptable:

- **Dashboards and reports** — showing data from 2 of 3 shards is better than showing an error page
- **Search results** — partial results are better than no results
- **List views** — users can still browse available data
- **Aggregation endpoints** — approximate counts are better than failures
- **Background jobs** — batch processing can retry failed shards later

### Disable for Write Operations

Dead shard tolerance should **never** be enabled for write paths:

- **Creating records** — a write must go to the correct shard; silently skipping it loses data
- **Updating records** — the record may be on the unavailable shard, leading to stale data
- **Deleting records** — same as updates; the target record may be unreachable
- **Transactions** — partial transaction execution causes data inconsistency

Write operations target a specific shard (via `onShard()` or shard key routing), so dead shard tolerance does not apply to them. If the target shard is down, the write fails with an exception — which is the correct behavior.

### Environment-Specific Recommendations

| Environment | Recommended Setting | Reason |
|---|---|---|
| Production | `true` (for reads) | Graceful degradation, partial data > no data |
| Staging | `false` | Surface issues before they reach production |
| Development | `false` | Catch connection problems early |
| Testing | `false` | Tests should fail explicitly on shard errors |

---

## Monitoring Shard Health

### Health Check Command

Shardwise includes an Artisan command to check the health of all configured shards:

```bash
php artisan shardwise:health
```

This runs a health check query (`SELECT 1` by default) against each shard and reports the status and latency.

### Health Check Configuration

```php
// config/shardwise.php
'health' => [
    // Timeout for health check queries in seconds
    'timeout' => 5,

    // Query to execute for health checks
    'query' => 'SELECT 1',
],
```

### Programmatic Health Checks

Use the `ShardHealthChecker` class for programmatic health monitoring:

```php
use Skylence\Shardwise\Health\ShardHealthChecker;

$checker = app(ShardHealthChecker::class);
$shards = shardwise()->getShards();

// Check all shards
$results = $checker->checkAll($shards);

foreach ($results as $shardId => $result) {
    if ($result->isHealthy()) {
        echo "{$shardId}: healthy ({$result->getLatency()}ms)";
    } else {
        echo "{$shardId}: unhealthy — {$result->getError()}";
    }
}

// Quick checks
$allHealthy = $checker->allHealthy($shards);
$healthyShards = $checker->getHealthyShards($shards);
$unhealthyShards = $checker->getUnhealthyShards($shards);
```

### Integrating With Application Monitoring

Set up a scheduled health check to track shard status over time:

```php
// app/Console/Kernel.php or routes/console.php
use Skylence\Shardwise\Health\ShardHealthChecker;

Schedule::call(function () {
    $checker = app(ShardHealthChecker::class);
    $shards = shardwise()->getShards();
    $results = $checker->checkAll($shards);

    foreach ($results as $shardId => $result) {
        if (! $result->isHealthy()) {
            Log::critical("Shard {$shardId} is unhealthy", [
                'error' => $result->getError(),
                'latency_ms' => $result->getLatency(),
            ]);

            // Send alert via your monitoring system
            // Slack, PagerDuty, etc.
        }
    }
})->everyMinute();
```

---

## Caveats and Considerations

### Partial Results May Be Misleading

When a shard is down, all numbers are lower than actual:

- A count of 8,000 may actually be 12,000
- A sum of revenue may be missing a third of transactions
- An average may be skewed if the down shard has different data characteristics

Consider displaying a warning in the UI when partial results are being served:

```php
$shards = shardwise()->getShards();
$checker = app(ShardHealthChecker::class);
$unhealthy = $checker->getUnhealthyShards($shards);

$isDegraded = $unhealthy->count() > 0;

// Pass to view
return view('dashboard', [
    'tickets' => $tickets,
    'isDegraded' => $isDegraded,
    'unavailableShards' => $unhealthy->count(),
]);
```

```blade
@if($isDegraded)
    <div class="alert alert-warning">
        {{ $unavailableShards }} database shard(s) are currently unavailable.
        Some data may be incomplete.
    </div>
@endif
```

### Tolerance Applies to All Exception Types

Dead shard tolerance catches all `Throwable` exceptions from shard operations — not just connection errors. This means query syntax errors, permission issues, or schema mismatches would also be silently swallowed. Keep your shard schemas synchronized and test queries against all shards during development with tolerance disabled.

### Recovery Is Automatic

Once a shard comes back online, subsequent cross-shard operations automatically include it again. There is no manual recovery step needed. The next `onAllShards()` call will query the restored shard as usual.

### No Retry Mechanism

Dead shard tolerance does not retry failed shards. If a shard times out, it is skipped for that operation. If you need retry logic, implement it at the application level or use a circuit breaker pattern.

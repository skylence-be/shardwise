# Cross-Shard Aggregations

Aggregating data across shards requires careful handling. Some aggregation operations are straightforward to distribute (like `count` and `sum`), while others are mathematically incorrect if naively combined (like `avg`). This guide explains how Shardwise handles each aggregation type correctly.

## The Problem: Average of Averages

The most common mistake in distributed aggregation is computing the average of per-shard averages. This produces mathematically incorrect results when shards have different amounts of data.

### Example

Consider an `orders` table with a `total` column, distributed across two shards:

| | Shard A | Shard B |
|---|---|---|
| Number of orders | 10 | 90 |
| Sum of totals | $1,000 | $4,500 |
| Average total | $100.00 | $50.00 |

**Naive approach (WRONG):**

Average the per-shard averages:

($100.00 + $50.00) / 2 = **$75.00**

This is wrong because it gives equal weight to both shards, even though Shard B has 9x more orders.

**Correct approach:**

Compute the weighted average using total sum and total count:

($1,000 + $4,500) / (10 + 90) = $5,500 / 100 = **$55.00**

The correct answer is $55.00 — significantly different from the naive $75.00.

---

## How Shardwise Handles Aggregations

Shardwise provides correct cross-shard aggregations through the `ShardableBuilder` (via `onAllShards()`) and the `CrossShardBuilder` (via `crossShard()`). Each aggregation type uses the appropriate mathematical strategy.

### count()

Sums the count from each shard. Always correct.

```php
// Total tickets across all shards
$total = Ticket::onAllShards()->count();

// With filters
$openCount = Ticket::onAllShards()
    ->where('status', 'open')
    ->count();
```

**How it works**: Each shard runs `SELECT COUNT(*) FROM tickets`. The results are summed.

### sum()

Sums the values from each shard. Always correct.

```php
// Total revenue across all shards
$revenue = Order::onAllShards()->sum('total');

// With filters
$monthlyRevenue = Order::onAllShards()
    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
    ->sum('total');
```

**How it works**: Each shard runs `SELECT SUM(total) FROM orders`. The results are summed.

### avg()

Computes a weighted average using total sum divided by total count across all shards. This is the correct mathematical approach.

```php
// Correct weighted average across all shards
$avgTotal = Order::onAllShards()->avg('total');

// With filters
$avgHighValue = Order::onAllShards()
    ->where('total', '>', 100)
    ->avg('total');
```

**How it works**: Each shard runs both `SELECT SUM(total) FROM orders` and `SELECT COUNT(*) FROM orders`. The global average is computed as `totalSum / totalCount`. This avoids the average-of-averages error.

### min()

Compares the minimum value from each shard and returns the smallest. Always correct.

```php
// Earliest order across all shards
$firstOrder = Order::onAllShards()->min('created_at');

// Lowest price across all shards
$cheapest = Product::onAllShards()->min('price');
```

**How it works**: Each shard runs `SELECT MIN(column) FROM table`. The global minimum is the smallest of the per-shard minimums.

### max()

Compares the maximum value from each shard and returns the largest. Always correct.

```php
// Most recent order across all shards
$latestOrder = Order::onAllShards()->max('created_at');

// Highest price across all shards
$mostExpensive = Product::onAllShards()->max('price');
```

**How it works**: Each shard runs `SELECT MAX(column) FROM table`. The global maximum is the largest of the per-shard maximums.

---

## Per-Shard Breakdown

Sometimes you need to see aggregation results per shard — for monitoring, dashboards, or debugging uneven data distribution. Use the `CrossShardBuilder` directly:

### Count Per Shard

```php
$perShard = Ticket::onAllShards()
    ->crossShard()
    ->countGroupedByShard();

// Returns: Illuminate\Support\Collection
// ['shard-1' => 4200, 'shard-2' => 3800, 'shard-3' => 4100]
```

### Results Per Shard

```php
$perShard = Ticket::onAllShards()
    ->crossShard()
    ->getGroupedByShard();

// Returns: Collection keyed by shard ID, each value is a Collection of models
// ['shard-1' => Collection<Ticket>, 'shard-2' => Collection<Ticket>, ...]
```

---

## Aggregations on CrossShardHasMany

The `CrossShardHasMany` relation also provides correct cross-shard aggregations:

```php
// Count all tickets for an agent across shards
$count = $agent->tickets()->count();

// Sum billable hours
$hours = $agent->tickets()->sum('billable_hours');

// Min/Max
$oldest = $agent->tickets()->min('created_at');
$newest = $agent->tickets()->max('created_at');

// With constraints
$openCount = $agent->tickets()->where('status', 'open')->count();
```

---

## Using the CrossShardBuilder Directly

For more control, access the `CrossShardBuilder` through the `crossShard()` method:

```php
$builder = Ticket::onAllShards()->crossShard();

// Target specific shards
$builder->only(['shard-1', 'shard-2'])->count();

// Exclude specific shards
$builder->except(['shard-3'])->count();

// Check existence (short-circuits on first match)
$exists = Ticket::onAllShards()->crossShard()->exists();
```

---

## Combining Aggregations

When you need multiple aggregations, each call runs independently across all shards. To minimize round trips, consider fetching raw data and computing aggregations in PHP:

```php
// Two separate cross-shard operations (2 x N shard queries)
$count = Order::onAllShards()->count();
$sum = Order::onAllShards()->sum('total');
$avg = $count > 0 ? $sum / $count : 0;

// Alternatively, use crossShard() for the avg which does this internally
$avg = Order::onAllShards()->avg('total');
```

---

## What About GROUP BY?

Standard SQL `GROUP BY` does not distribute correctly across shards in a single query. If you need grouped aggregations (e.g., count per status), you have two options:

### Option 1: Fetch and Group in PHP

```php
$tickets = Ticket::onAllShards()->get(['status']);

$grouped = $tickets->groupBy('status')->map->count();
// ['open' => 120, 'closed' => 340, 'pending' => 55]
```

This loads all matching rows into memory but produces correct results.

### Option 2: Run Per-Shard GROUP BY and Merge

```php
$builder = Ticket::onAllShards()->crossShard();
$perShard = $builder->getGroupedByShard(['status', DB::raw('COUNT(*) as count')]);

// Merge per-shard grouped results
$totals = collect();
foreach ($perShard as $shardId => $rows) {
    foreach ($rows as $row) {
        $current = $totals->get($row->status, 0);
        $totals->put($row->status, $current + $row->count);
    }
}
```

This is more memory-efficient for large datasets since each shard returns pre-aggregated data.

---

## Performance Notes

- Each aggregation call makes one query per shard. With 5 shards, `count()` executes 5 queries.
- Aggregation queries are lightweight (`SELECT COUNT(*)`, `SELECT SUM(column)`) and typically very fast.
- The `avg()` method makes two queries per shard (one for `SUM`, one for `COUNT`), so it costs 2x compared to `count()` or `sum()`.
- Dead shard tolerance (if enabled) causes aggregations to return partial results from healthy shards only. Be aware that counts and sums will be lower than actual when a shard is down.

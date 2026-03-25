# Cross-Shard Pagination & Ordering

Paginating data that is distributed across multiple database shards is fundamentally different from paginating a single table. This guide explains how Shardwise handles it, the performance trade-offs involved, and which approach to choose for your use case.

## How It Works

When you call `Model::onAllShards()->orderBy('created_at', 'desc')->paginate(15)`, Shardwise executes the following steps:

### Step 1: Count Across All Shards

A `COUNT(*)` query runs on each shard. The results are summed to determine the total number of records across all shards. This total is used for the `LengthAwarePaginator` to calculate the correct number of pages.

### Step 2: Fetch Rows From Each Shard

Each shard returns up to `offset + perPage` rows, ordered by the specified column. Every shard must return this many rows because data distribution is unknown — all matching rows could theoretically be on a single shard.

For example, requesting page 1 with 15 items per page:
- Each shard fetches up to `0 + 15 = 15` rows
- With 3 shards, that is 45 rows total fetched

### Step 3: Merge and Re-Sort

Results from all shards are merged into a single collection. A global re-sort is applied across the merged results using the same ordering criteria. This is necessary because per-shard ordering only guarantees order within each shard, not across the combined result set.

### Step 4: Slice the Page Window

The correct page window is sliced from the globally sorted results. For page 1, rows 0-14 are taken. For page 3, rows 30-44 are taken. The remaining rows are discarded.

---

## Performance Characteristics

Cross-shard pagination performance degrades linearly with page depth. Here is the math:

| Page | Per Page | Rows Fetched Per Shard | Total Fetched (3 shards) | Rows Kept |
|------|----------|----------------------|--------------------------|-----------|
| 1 | 15 | 15 | 45 | 15 |
| 10 | 15 | 150 | 450 | 15 |
| 100 | 15 | 1,500 | 4,500 | 15 |
| 1,000 | 15 | 15,000 | 45,000 | 15 |

The further into the dataset you paginate, the more rows must be fetched, sorted in memory, and discarded. Page 1,000 with 3 shards fetches 45,000 rows to display 15.

### Why This Is Unavoidable

Without a global index, there is no way to know which shard holds the 15,001st row in global sort order. Each shard must provide enough rows to cover the possibility that all relevant rows are on that shard. This is a fundamental limitation of distributed pagination — not specific to Shardwise.

---

## Code Examples

### Basic Pagination

The simplest approach. Works well for shallow pages (pages 1 through ~50):

```php
// Paginate with ordering (correct across shards)
$tickets = Ticket::onAllShards()
    ->orderBy('created_at', 'desc')
    ->paginate(15);

// Use in Blade just like normal pagination
$tickets->links();
```

The ordering specified on the builder is automatically extracted and used for both per-shard queries and global re-sorting.

### Cursor-Based Pagination

For deeper pagination or infinite scroll UIs, cursor-based pagination is significantly more efficient. Instead of calculating an offset, it uses a cursor value (typically the last seen ID or timestamp) to fetch the next batch:

```php
use Skylence\Shardwise\Query\CrossShardPaginator;

$paginator = new CrossShardPaginator(Ticket::query());

// First page
$results = $paginator->cursorPaginate(
    perPage: 15,
    columns: ['*'],
    cursorColumn: 'created_at',
    direction: 'desc',
    cursor: null,
);

// Next page — pass the last item's cursor value
$lastItem = $results->last();
$nextResults = $paginator->cursorPaginate(
    perPage: 15,
    columns: ['*'],
    cursorColumn: 'created_at',
    direction: 'desc',
    cursor: $lastItem->created_at,
);
```

With cursor pagination, each shard always fetches only `perPage` rows regardless of how deep into the dataset you are. For 3 shards with 15 items per page, you always fetch 45 rows total — whether you are on "page 1" or "page 1,000."

### Targeting a Single Shard

If you know which shard holds the data (e.g., you have a shard key from the URL), target it directly. Single-shard pagination is standard Laravel pagination with no overhead:

```php
// Direct shard targeting — standard Laravel pagination, no cross-shard cost
$tickets = Ticket::onShard('shard-1')
    ->where('tenant_id', $tenantId)
    ->orderBy('created_at', 'desc')
    ->paginate(15);
```

### Paginating CrossShardHasMany

The `CrossShardHasMany` relation supports manual pagination through `offset()` and `limit()`:

```php
$page = request()->input('page', 1);
$perPage = 15;

$tickets = $agent->tickets()
    ->orderBy('created_at', 'desc')
    ->offset(($page - 1) * $perPage)
    ->limit($perPage)
    ->get();
```

---

## Recommendations

### Use offset-based pagination when:
- The dataset is small (hundreds to low thousands of records)
- Users will not paginate past page ~50
- You need accurate page numbers and "go to page X" navigation
- Admin dashboards with filtered, limited datasets

### Use cursor-based pagination when:
- The dataset is large (tens of thousands+ records)
- The UI uses infinite scroll or "load more" patterns
- Users may scroll deeply into the results
- API endpoints consumed by mobile apps

### Use single-shard targeting when:
- You have a shard key available (tenant ID, user ID, etc.)
- The query naturally filters to a single shard
- You want standard Laravel pagination with zero cross-shard overhead

### Avoid deep offset-based pagination when:
- You have many shards (5+)
- The dataset has millions of rows
- Response time is critical

---

## Ordering Considerations

### Multi-Column Ordering

Multi-column ordering works across shards. The `ShardableBuilder` applies all `ORDER BY` clauses during the global re-sort:

```php
Ticket::onAllShards()
    ->orderBy('priority', 'desc')
    ->orderBy('created_at', 'asc')
    ->paginate(15);
```

### Ordering Without Pagination

If you only need ordering without pagination, the same merge-and-sort process applies:

```php
// Gets ALL tickets from ALL shards, sorted globally
$tickets = Ticket::onAllShards()
    ->orderBy('created_at', 'desc')
    ->get();
```

Be mindful that this loads all matching rows into memory.

### Ordering With Limits

Combining ordering with limits works correctly:

```php
// Get the 10 most recent tickets across all shards
$tickets = Ticket::onAllShards()
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
```

Each shard fetches 10 rows, the results are merged (up to 30 rows for 3 shards), globally sorted, and the top 10 are returned.

---

## Memory Usage

Cross-shard pagination holds all fetched rows in memory during the merge and sort phase. For deep pages, this can be significant:

- Page 100, 15 per page, 3 shards = 4,500 Eloquent models in memory
- Page 1,000, 15 per page, 10 shards = 150,000 Eloquent models in memory

If memory is a concern, use cursor-based pagination or the `lazy()` / `cursor()` methods on `ShardableBuilder` for streaming results without loading everything at once:

```php
// Stream results from all shards without loading into memory
Ticket::onAllShards()->lazy(1000)->each(function (Ticket $ticket) {
    // Process one at a time
});

// Even more memory-efficient with database cursors
Ticket::onAllShards()->cursor()->each(function (Ticket $ticket) {
    // Process one at a time
});
```

Note that `lazy()` and `cursor()` do not support global ordering — results arrive in shard order, not globally sorted.

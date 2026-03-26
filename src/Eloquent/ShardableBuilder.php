<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent;

use Amp\Postgres\PostgresConnectionPool;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\LazyCollection;
use Skylence\Shardwise\Async\AsyncShardQueryExecutor;
use Skylence\Shardwise\Contracts\ShardableInterface;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Query\CrossShardBuilder;
use Skylence\Shardwise\Query\CrossShardPaginator;
use Skylence\Shardwise\ShardCollection;
use Skylence\Shardwise\Uuid\UuidShardDecoder;
use Throwable;

/**
 * Shard-aware Eloquent query builder.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class ShardableBuilder extends Builder
{
    /**
     * The target shard for this query.
     */
    private ?ShardInterface $targetShard = null;

    /**
     * Whether this query should run on all shards.
     */
    private bool $allShards = false;

    /**
     * Execute the query on a specific shard.
     *
     * @return $this
     */
    public function onShard(ShardInterface|string $shard): self
    {
        if (is_string($shard)) {
            $shard = shardwise()->getShard($shard);
        }

        $this->targetShard = $shard;
        $this->allShards = false;

        // Set the connection for the query
        /** @var DatabaseManager $db */
        $db = app('db');
        $this->getQuery()->connection = $db->connection($shard->getConnectionName());

        return $this;
    }

    /**
     * Execute the query on all shards.
     *
     * @return $this
     */
    public function onAllShards(): self
    {
        $this->allShards = true;
        $this->targetShard = null;

        return $this;
    }

    /**
     * Get the target shard for this query.
     */
    public function getTargetShard(): ?ShardInterface
    {
        return $this->targetShard;
    }

    /**
     * Check if this query targets all shards.
     */
    public function isAllShards(): bool
    {
        return $this->allShards;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array<int, string>|string  $columns
     * @return Collection<int, TModel>
     */
    public function get($columns = ['*']): Collection
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->get($columns);
                }
            }

            return $this->getFromAllShards($columns);
        }

        return parent::get($columns);
    }

    /**
     * Get the count of matching records from all shards.
     *
     * @param  string  $columns
     */
    public function count($columns = '*'): int
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->count($columns);
                }
            }

            return $this->countFromAllShards($columns);
        }

        return parent::count($columns);
    }

    /**
     * Get the sum of a column from all shards.
     *
     * @param  string  $column
     */
    public function sum($column): float|int
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->sum($column);
                }
            }

            return $this->aggregateFromAllShardsAmphpOrFallback('sum', $column)
                ?? $this->crossShard()->sum($column);
        }

        return (float) parent::sum($column);
    }

    /**
     * Get the average of a column from all shards.
     *
     * Uses weighted average (total sum / total count) for correctness.
     *
     * @param  string  $column
     */
    public function avg($column): float|int
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->avg($column);
                }
            }

            // For avg, we need both sum and count — fire both as concurrent scalars
            $shards = shardwise()->getShards()->active();
            if ($this->isAmphpAvailable() && $shards->count() > 1) {
                try {
                    $sumSql = $this->buildAggregateSql("sum(\"{$column}\")");
                    $countSql = $this->buildAggregateSql('count(*)');
                    $bindings = $this->getAggregateBindings();
                    $tolerant = (bool) config('shardwise.dead_shard_tolerance', false);

                    // Both are scalar queries — use scalarAll for each
                    $sumResults = AsyncShardQueryExecutor::scalarAll($shards, $sumSql, $bindings, $tolerant);
                    $countResults = AsyncShardQueryExecutor::scalarAll($shards, $countSql, $bindings, $tolerant);

                    $totalSum = 0.0;
                    $totalCount = 0;
                    foreach ($sumResults as $v) {
                        $totalSum += (float) ($v ?? 0);
                    }
                    foreach ($countResults as $v) {
                        $totalCount += (int) ($v ?? 0);
                    }

                    return $totalCount > 0 ? $totalSum / $totalCount : 0.0;
                } catch (Throwable) {
                    // Fall through to CrossShardBuilder
                }
            }

            return $this->crossShard()->avg($column);
        }

        return parent::avg($column);
    }

    /**
     * Get the minimum value of a column from all shards.
     *
     * @param  string  $column
     */
    public function min($column): mixed
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->min($column);
                }
            }

            return $this->aggregateFromAllShardsAmphpOrFallback('min', $column)
                ?? $this->crossShard()->min($column);
        }

        return parent::min($column);
    }

    /**
     * Get the maximum value of a column from all shards.
     *
     * @param  string  $column
     */
    public function max($column): mixed
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->max($column);
                }
            }

            return $this->aggregateFromAllShardsAmphpOrFallback('max', $column)
                ?? $this->crossShard()->max($column);
        }

        return parent::max($column);
    }

    /**
     * Paginate results, using CrossShardPaginator when querying all shards.
     *
     * @param  int|Closure  $perPage
     * @param  array<int, string>|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  Closure|int|null  $total
     */
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null): LengthAwarePaginator
    {
        if ($this->allShards) {
            if (config('shardwise.co_location.enabled', true)) {
                $targetShard = $this->resolveShardFromConstraints();
                if ($targetShard !== null) {
                    return $this->onShard($targetShard)->paginate($perPage, $columns, $pageName, $page, $total);
                }
            }

            $paginator = new CrossShardPaginator($this);

            // Extract ordering from the builder
            $orders = $this->getQuery()->orders ?? [];
            $orderColumn = $orders[0]['column'] ?? null;
            $orderDirection = $orders[0]['direction'] ?? 'asc';

            return $paginator->paginate(
                $perPage instanceof Closure ? $perPage() : (int) $perPage,
                is_array($columns) ? $columns : [$columns],
                $pageName,
                $page !== null ? (int) $page : null,
                $orderColumn,
                $orderDirection,
            );
        }

        return parent::paginate($perPage, $columns, $pageName, $page, $total);
    }

    /**
     * Get a cross-shard builder for aggregations.
     *
     * @return CrossShardBuilder<TModel>
     */
    public function crossShard(): CrossShardBuilder
    {
        return new CrossShardBuilder($this);
    }

    /**
     * Get a lazy collection of models.
     *
     * When using onAllShards(), this streams results from all shards
     * without loading everything into memory.
     *
     * @param  int  $chunkSize
     * @return LazyCollection<int, TModel>
     */
    public function lazy($chunkSize = 1000): LazyCollection
    {
        if ($this->allShards) {
            return $this->lazyFromAllShards($chunkSize);
        }

        return parent::lazy($chunkSize);
    }

    /**
     * Get a cursor for the query.
     *
     * When using onAllShards(), this streams results from all shards
     * using database cursors for memory efficiency.
     *
     * @return LazyCollection<int, TModel>
     */
    public function cursor(): LazyCollection
    {
        if ($this->allShards) {
            return $this->cursorFromAllShards();
        }

        return parent::cursor();
    }

    /**
     * Find a model by its primary key, routing to the appropriate shard.
     *
     * @param  mixed  $id
     * @param  array<int, string>|string  $columns
     * @return TModel|Collection<int, TModel>|null
     */
    public function find($id, $columns = ['*']): Model|Collection|null
    {
        // If no shard is targeted yet, try to decode shard from UUID
        if ($this->targetShard === null && ! $this->allShards && is_string($id)) {
            $decoder = UuidShardDecoder::fromConfig(shardwise()->getShards());
            $shard = $decoder->decode($id);

            if ($shard !== null) {
                return $this->onShard($shard)->find($id, $columns);
            }
        }

        // Fallback: try model's resolveShard() if model has attributes set
        if ($this->targetShard === null && ! $this->allShards && $this->model instanceof ShardableInterface) {
            $shard = $this->model->resolveShard();

            if ($shard !== null) {
                return $this->onShard($shard)->find($id, $columns);
            }
        }

        return parent::find($id, $columns);
    }

    /**
     * Eagerly load a relation, ensuring related models with fixed connections
     * (e.g. CentralModel or explicit $connection) use their own connection
     * instead of the current shard connection.
     *
     * @param  array<int, TModel>  $models
     * @return array<int, TModel>
     */
    protected function eagerLoadRelation(array $models, $name, Closure $constraints): array
    {
        $relation = $this->getRelation($name);
        $relatedModel = $relation->getRelated();

        // If the related model has a fixed connection (CentralModel or explicit),
        // ensure the relation query uses that connection instead of the shard connection
        $relatedConnection = $relatedModel->getConnectionName();

        if ($relatedConnection !== null) {
            /** @var DatabaseManager $db */
            $db = app('db');
            $relation->getBaseQuery()->connection = $db->connection($relatedConnection);
        }

        $relation->addEagerConstraints($models);
        $constraints($relation);

        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(),
            $name
        );
    }

    /**
     * Resolve a target shard from the query's WHERE constraints.
     *
     * When the query contains a WHERE clause on the model's shard key column
     * with a basic equality operator, we can route directly to that shard
     * instead of querying all shards.
     */
    private function resolveShardFromConstraints(): ?ShardInterface
    {
        if (! $this->model instanceof ShardableInterface) {
            return null;
        }

        $shardKeyColumn = $this->model->getShardKeyColumn();
        $wheres = $this->getQuery()->wheres;

        // Abort if any OR conditions exist — the query spans multiple logical branches
        // and co-location on one branch would miss data from the other
        foreach ($wheres as $where) {
            if (strtolower($where['boolean'] ?? 'and') === 'or') {
                return null;
            }
        }

        foreach ($wheres as $where) {
            if (($where['column'] ?? null) === $shardKeyColumn
                && ($where['type'] ?? null) === 'Basic'
                && ($where['operator'] ?? null) === '=') {
                try {
                    return shardwise()->route($where['value']);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Get results from all shards and merge them.
     *
     * Captures ordering and limit/offset from the query, strips them from
     * per-shard clones, then applies global ordering and pagination after
     * merging results from all shards.
     *
     * @param  array<int, string>|string  $columns
     * @return Collection<int, TModel>
     */
    private function getFromAllShards(array|string $columns = ['*']): Collection
    {
        /** @var array<int, string> $columns */
        $columns = is_array($columns) ? $columns : func_get_args();

        $shards = shardwise()->getShards()->active();

        // Capture ordering and limit/offset from the underlying query
        $query = $this->getQuery();
        $orders = $query->orders ?? [];
        $originalLimit = $query->limit;
        $originalOffset = $query->offset;

        // Calculate how many items each shard needs to return
        $fetchLimit = $originalLimit !== null
            ? ($originalOffset ?? 0) + $originalLimit
            : null;

        $useParallel = config('shardwise.parallel_queries.enabled', false)
            && $shards->count() > 1;

        $driver = config('shardwise.parallel_queries.driver', 'concurrency');
        $useAmphp = $useParallel
            && $driver === 'amphp'
            && class_exists(PostgresConnectionPool::class);

        if ($useAmphp) {
            $results = $this->getFromShardsAmphp($shards, $columns, $fetchLimit, $orders);
        } elseif ($useParallel && class_exists(Concurrency::class)) {
            $results = $this->getFromShardsParallel($shards, $columns, $fetchLimit, $orders);
        } else {
            $results = $this->getFromShardsSequential($shards, $columns, $fetchLimit, $orders);
        }

        // Apply global ordering
        if (! empty($orders)) {
            $results = $this->applyCrossShardOrdering($results, $orders);
        }

        // Apply global offset/limit
        if ($originalOffset !== null || $originalLimit !== null) {
            $results = $results->slice($originalOffset ?? 0, $originalLimit)->values();
        }

        return $results;
    }

    /**
     * Get results from shards sequentially.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array{column: string, direction: string}>  $orders
     * @return Collection<int, TModel>
     */
    private function getFromShardsSequential(ShardCollection $shards, array $columns, ?int $fetchLimit, array $orders): Collection
    {
        $results = new Collection;

        foreach ($shards as $shard) {
            try {
                $shardResults = shardwise()->run($shard, function () use ($shard, $columns, $fetchLimit, $orders): Collection {
                    // Build a fresh query on the shard connection to avoid clone/connection issues
                    $model = $this->getModel();
                    $fresh = $model->on($shard->getConnectionName())->newQuery();

                    // Re-apply wheres from the original builder
                    $fresh->getQuery()->wheres = $this->getQuery()->wheres;
                    $fresh->getQuery()->bindings = $this->getQuery()->bindings;

                    // Re-apply ordering
                    foreach ($orders as $order) {
                        $fresh->orderBy($order['column'], $order['direction'] ?? 'asc');
                    }

                    // Re-apply eager loads
                    $fresh->setEagerLoads($this->getEagerLoads());

                    // Override limit to fetch enough for global pagination
                    if ($fetchLimit !== null) {
                        $fresh->limit($fetchLimit);
                    }

                    return $fresh->get($columns);
                });

                $results = $results->merge($shardResults);
            } catch (Throwable $e) {
                if (config('shardwise.dead_shard_tolerance', false)) {
                    continue;
                }

                throw $e;
            }
        }

        return $results;
    }

    /**
     * Get results from shards in parallel using Laravel's Concurrency facade.
     *
     * Each shard query runs in a separate process with its own database
     * connection. Results are serialized/deserialized between processes.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array{column: string, direction: string}>  $orders
     * @return Collection<int, TModel>
     */
    private function getFromShardsParallel(ShardCollection $shards, array $columns, ?int $fetchLimit, array $orders): Collection
    {
        $modelClass = get_class($this->model);
        $wheres = $this->getQuery()->wheres;
        $bindings = $this->getQuery()->bindings;
        $eagerLoads = $this->getEagerLoads();

        $callbacks = [];
        foreach ($shards as $shard) {
            $shardId = $shard->getId();
            $connectionName = $shard->getConnectionName();

            $callbacks[$shardId] = function () use ($modelClass, $connectionName, $wheres, $bindings, $columns, $fetchLimit, $orders, $eagerLoads): array {
                /** @var Model $model */
                $model = new $modelClass;
                $fresh = $model->on($connectionName)->newQuery();
                $fresh->getQuery()->wheres = $wheres;
                $fresh->getQuery()->bindings = $bindings;

                foreach ($orders as $order) {
                    $fresh->orderBy($order['column'], $order['direction'] ?? 'asc');
                }

                $fresh->setEagerLoads($eagerLoads);

                if ($fetchLimit !== null) {
                    $fresh->limit($fetchLimit);
                }

                return $fresh->get($columns)->toArray();
            };
        }

        try {
            /** @var array<string, array<int, array<string, mixed>>> $shardResults */
            $shardResults = Concurrency::run($callbacks);

            $results = new Collection;
            $model = $this->getModel();
            foreach ($shardResults as $shardData) {
                foreach ($shardData as $row) {
                    $results->push($model->newFromBuilder((object) $row));
                }
            }

            return $results;
        } catch (Throwable) {
            // Fall back to sequential if parallel fails
            return $this->getFromShardsSequential($shards, $columns, $fetchLimit, $orders);
        }
    }

    /**
     * Get results from shards concurrently using AmPHP Fibers.
     *
     * Builds the SQL from the current Eloquent query state and fires all
     * shard queries simultaneously via amphp/postgres connection pools.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array{column: string, direction: string}>  $orders
     * @return Collection<int, TModel>
     */
    private function getFromShardsAmphp(ShardCollection $shards, array $columns, ?int $fetchLimit, array $orders): Collection
    {
        $model = $this->getModel();
        $fresh = $model->newQuery();
        $fresh->getQuery()->wheres = $this->getQuery()->wheres;
        $fresh->getQuery()->bindings = $this->getQuery()->bindings;

        foreach ($orders as $order) {
            $fresh->orderBy($order['column'], $order['direction'] ?? 'asc');
        }

        if ($fetchLimit !== null) {
            $fresh->limit($fetchLimit);
        }

        if ($columns !== ['*']) {
            $fresh->select($columns);
        }

        $sql = $fresh->toSql();
        $bindings = $fresh->getBindings();
        $tolerateDeadShards = (bool) config('shardwise.dead_shard_tolerance', false);

        try {
            $shardResults = AsyncShardQueryExecutor::queryAll($shards, $sql, $bindings, $tolerateDeadShards);
        } catch (Throwable $e) {
            // Fall back to sequential if amphp fails entirely
            return $this->getFromShardsSequential($shards, $columns, $fetchLimit, $orders);
        }

        $results = new Collection;
        foreach ($shardResults as $rows) {
            foreach ($rows as $row) {
                $results->push($model->newFromBuilder((object) $row));
            }
        }

        return $results;
    }

    /**
     * Count from shards concurrently using AmPHP Fibers.
     *
     * Builds minimal SQL directly to avoid Eloquent builder overhead.
     */
    private function countFromShardsAmphp(ShardCollection $shards, string $columns = '*'): int
    {
        $model = $this->getModel();
        $table = $model->getTable();
        $wheres = $this->getQuery()->wheres;
        $bindings = array_values($this->getQuery()->getRawBindings()['where'] ?? []);

        // Build minimal count SQL directly — skip Eloquent builder overhead
        $sql = "select count({$columns}) as aggregate from \"{$table}\"";

        if (! empty($wheres)) {
            // Use Eloquent's grammar to compile WHERE clauses correctly
            $fresh = $model->newQuery();
            $fresh->getQuery()->wheres = $wheres;
            $fresh->getQuery()->bindings = $this->getQuery()->bindings;
            $fresh->selectRaw("count({$columns}) as aggregate");
            $sql = $fresh->toSql();
            $bindings = $fresh->getBindings();
        }

        $tolerateDeadShards = (bool) config('shardwise.dead_shard_tolerance', false);

        try {
            $shardResults = AsyncShardQueryExecutor::scalarAll($shards, $sql, $bindings, $tolerateDeadShards);
        } catch (Throwable) {
            return $this->countFromShardsSequential($shards, $columns);
        }

        $total = 0;
        foreach ($shardResults as $count) {
            $total += (int) $count;
        }

        return $total;
    }

    /**
     * Check if AmPHP async driver is available and enabled.
     */
    private function isAmphpAvailable(): bool
    {
        return config('shardwise.parallel_queries.enabled', false)
            && config('shardwise.parallel_queries.driver', 'amphp') === 'amphp'
            && class_exists(PostgresConnectionPool::class);
    }

    /**
     * Build aggregate SQL directly, bypassing Eloquent builder overhead.
     */
    private function buildAggregateSql(string $aggregateExpr): string
    {
        $model = $this->getModel();
        $wheres = $this->getQuery()->wheres;

        if (empty($wheres)) {
            return "select {$aggregateExpr} as aggregate from \"{$model->getTable()}\"";
        }

        // Use Eloquent grammar for complex WHERE clauses
        $fresh = $model->newQuery();
        $fresh->getQuery()->wheres = $wheres;
        $fresh->getQuery()->bindings = $this->getQuery()->bindings;
        $fresh->selectRaw("{$aggregateExpr} as aggregate");

        return $fresh->toSql();
    }

    /**
     * Get bindings for aggregate queries.
     *
     * @return array<int, mixed>
     */
    private function getAggregateBindings(): array
    {
        $wheres = $this->getQuery()->wheres;

        if (empty($wheres)) {
            return [];
        }

        $model = $this->getModel();
        $fresh = $model->newQuery();
        $fresh->getQuery()->wheres = $wheres;
        $fresh->getQuery()->bindings = $this->getQuery()->bindings;
        $fresh->selectRaw('1');

        return $fresh->getBindings();
    }

    /**
     * Try to run an aggregate via AmPHP, return null to fall through to CrossShardBuilder.
     */
    private function aggregateFromAllShardsAmphpOrFallback(string $function, string $column): float|int|null
    {
        $shards = shardwise()->getShards()->active();

        if (! $this->isAmphpAvailable() || $shards->count() <= 1) {
            return null;
        }

        try {
            $sql = $this->buildAggregateSql("{$function}(\"{$column}\")");
            $bindings = $this->getAggregateBindings();
            $tolerant = (bool) config('shardwise.dead_shard_tolerance', false);

            $results = AsyncShardQueryExecutor::scalarAll($shards, $sql, $bindings, $tolerant);

            return match ($function) {
                'sum' => (float) array_sum(array_map('floatval', $results)),
                'min' => min(array_filter($results, fn ($v) => $v !== null)),
                'max' => max(array_filter($results, fn ($v) => $v !== null)),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Count from all shards.
     */
    private function countFromAllShards(string $columns = '*'): int
    {
        $shards = shardwise()->getShards()->active();

        $useParallel = config('shardwise.parallel_queries.enabled', false)
            && $shards->count() > 1;

        $driver = config('shardwise.parallel_queries.driver', 'concurrency');
        $useAmphp = $useParallel
            && $driver === 'amphp'
            && class_exists(PostgresConnectionPool::class);

        if ($useAmphp) {
            return $this->countFromShardsAmphp($shards, $columns);
        }

        if ($useParallel && class_exists(Concurrency::class)) {
            return $this->countFromShardsParallel($shards, $columns);
        }

        return $this->countFromShardsSequential($shards, $columns);
    }

    /**
     * Count from all shards sequentially.
     */
    private function countFromShardsSequential(ShardCollection $shards, string $columns = '*'): int
    {
        $total = 0;

        foreach ($shards as $shard) {
            try {
                $count = shardwise()->run($shard, function () use ($shard, $columns): int {
                    // Build a fresh query on the shard connection
                    $model = $this->getModel();
                    $fresh = $model->on($shard->getConnectionName())->newQuery();

                    // Re-apply wheres from the original builder
                    $fresh->getQuery()->wheres = $this->getQuery()->wheres;
                    $fresh->getQuery()->bindings = $this->getQuery()->bindings;

                    return $fresh->count($columns);
                });

                $total += $count;
            } catch (Throwable $e) {
                if (config('shardwise.dead_shard_tolerance', false)) {
                    continue;
                }

                throw $e;
            }
        }

        return $total;
    }

    /**
     * Count from all shards in parallel using Laravel's Concurrency facade.
     */
    private function countFromShardsParallel(ShardCollection $shards, string $columns = '*'): int
    {
        $modelClass = get_class($this->model);
        $wheres = $this->getQuery()->wheres;
        $bindings = $this->getQuery()->bindings;

        $callbacks = [];
        foreach ($shards as $shard) {
            $shardId = $shard->getId();
            $connectionName = $shard->getConnectionName();

            $callbacks[$shardId] = function () use ($modelClass, $connectionName, $wheres, $bindings, $columns): int {
                /** @var Model $model */
                $model = new $modelClass;
                $fresh = $model->on($connectionName)->newQuery();
                $fresh->getQuery()->wheres = $wheres;
                $fresh->getQuery()->bindings = $bindings;

                return $fresh->count($columns);
            };
        }

        try {
            /** @var array<string, int> $shardCounts */
            $shardCounts = Concurrency::run($callbacks);

            return array_sum($shardCounts);
        } catch (Throwable) {
            // Fall back to sequential if parallel fails
            return $this->countFromShardsSequential($shards, $columns);
        }
    }

    /**
     * Apply cross-shard ordering to merged results.
     *
     * Processes orders in reverse so the first (primary) order takes precedence,
     * using stable sort semantics from Laravel's Collection.
     *
     * @param  Collection<int, TModel>  $results
     * @param  array<int, array{column: string, direction: string}>  $orders
     * @return Collection<int, TModel>
     */
    private function applyCrossShardOrdering(Collection $results, array $orders): Collection
    {
        foreach (array_reverse($orders) as $order) {
            $column = $order['column'];
            $direction = strtolower($order['direction'] ?? 'asc');

            $results = $direction === 'desc'
                ? $results->sortByDesc($column)
                : $results->sortBy($column);
        }

        return $results->values();
    }

    /**
     * Get a lazy collection from all shards.
     *
     * @return LazyCollection<int, TModel>
     */
    private function lazyFromAllShards(int $chunkSize = 1000): LazyCollection
    {
        $builder = $this;

        return LazyCollection::make(function () use ($builder, $chunkSize) {
            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                yield from shardwise()->run($shard, function () use ($builder, $chunkSize): LazyCollection {
                    // Deep-clone the builder (including underlying query) to avoid shared connection state
                    $clone = $builder->clone();
                    $clone->allShards = false;

                    return $clone->lazy($chunkSize);
                });
            }
        });
    }

    /**
     * Get a cursor from all shards.
     *
     * @return LazyCollection<int, TModel>
     */
    private function cursorFromAllShards(): LazyCollection
    {
        $builder = $this;

        return LazyCollection::make(function () use ($builder) {
            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                yield from shardwise()->run($shard, function () use ($builder): LazyCollection {
                    // Deep-clone the builder (including underlying query) to avoid shared connection state
                    $clone = $builder->clone();
                    $clone->allShards = false;

                    return $clone->cursor();
                });
            }
        });
    }
}

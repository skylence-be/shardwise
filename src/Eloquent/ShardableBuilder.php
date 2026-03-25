<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Skylence\Shardwise\Contracts\ShardableInterface;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Query\CrossShardBuilder;
use Skylence\Shardwise\Query\CrossShardPaginator;
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
            return $this->crossShard()->sum($column);
        }

        return parent::sum($column);
    }

    /**
     * Get the average of a column from all shards.
     *
     * @param  string  $column
     */
    public function avg($column): float|int
    {
        if ($this->allShards) {
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
            return $this->crossShard()->min($column);
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
            return $this->crossShard()->max($column);
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

        $results = new Collection;

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

        foreach ($shards as $shard) {
            try {
                $shardResults = shardwise()->run($shard, function () use ($shard, $columns, $fetchLimit): Collection {
                    // Deep-clone the builder (including underlying query) to avoid shared connection state
                    $clone = $this->clone();
                    $clone->allShards = false;

                    // Explicitly set the shard connection on the cloned query builder
                    // (the clone inherits the original connection, not the shard context)
                    /** @var DatabaseManager $db */
                    $db = app('db');
                    $clone->getQuery()->connection = $db->connection($shard->getConnectionName());

                    // Override limit to fetch enough for global pagination
                    if ($fetchLimit !== null) {
                        $clone->getQuery()->limit = $fetchLimit;
                        $clone->getQuery()->offset = null;
                    }

                    // Call get() on the clone, which will now use parent::get() since allShards is false
                    return $clone->get($columns);
                });

                $results = $results->merge($shardResults);
            } catch (Throwable $e) {
                if (config('shardwise.dead_shard_tolerance', false)) {
                    continue;
                }

                throw $e;
            }
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
     * Count from all shards.
     */
    private function countFromAllShards(string $columns = '*'): int
    {
        $total = 0;

        $shards = shardwise()->getShards()->active();

        foreach ($shards as $shard) {
            try {
                $count = shardwise()->run($shard, function () use ($shard, $columns): int {
                    // Deep-clone the builder (including underlying query) to avoid shared connection state
                    $clone = $this->clone();
                    $clone->allShards = false;

                    // Explicitly set the shard connection on the cloned query builder
                    /** @var DatabaseManager $db */
                    $db = app('db');
                    $clone->getQuery()->connection = $db->connection($shard->getConnectionName());

                    // Call count() on the clone, which will now use parent::count() since allShards is false
                    return $clone->count($columns);
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

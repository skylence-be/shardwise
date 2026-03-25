<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\LazyCollection;
use Skylence\Shardwise\Concerns\DetectsScatterQueries;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardCollection;

/**
 * Builder for cross-shard queries and aggregations.
 *
 * WARNING: Cross-shard queries (scatter queries) execute on multiple shards
 * and merge results. This can significantly impact performance. Consider
 * using shard keys in WHERE clauses to target specific shards when possible.
 *
 * @template TModel of Model
 */
final class CrossShardBuilder
{
    use DetectsScatterQueries;

    /**
     * Results collected from each shard.
     *
     * @var array<string, mixed>
     */
    private array $shardResults = [];

    /**
     * Whether scatter query logging has been done for this builder.
     */
    private bool $scatterLogged = false;

    /**
     * @param  Builder<TModel>  $builder
     */
    public function __construct(
        private readonly Builder $builder,
        private ?ShardCollection $targetShards = null,
    ) {
        $this->targetShards ??= shardwise()->getShards()->active();
    }

    /**
     * Limit to specific shards.
     *
     * @param  array<int, ShardInterface|string>  $shards
     * @return $this
     */
    public function only(array $shards): self
    {
        $collection = shardwise()->getShards();
        $filtered = $collection->filter(function (ShardInterface $shard) use ($shards): bool {
            foreach ($shards as $target) {
                $targetId = $target instanceof ShardInterface ? $target->getId() : $target;
                if ($shard->getId() === $targetId) {
                    return true;
                }
            }

            return false;
        });

        $this->targetShards = $filtered;

        return $this;
    }

    /**
     * Exclude specific shards.
     *
     * @param  array<int, ShardInterface|string>  $shards
     * @return $this
     */
    public function except(array $shards): self
    {
        $this->targetShards = $this->targetShards?->filter(function (ShardInterface $shard) use ($shards): bool {
            foreach ($shards as $target) {
                $targetId = $target instanceof ShardInterface ? $target->getId() : $target;
                if ($shard->getId() === $targetId) {
                    return false;
                }
            }

            return true;
        });

        return $this;
    }

    /**
     * Execute the query on all target shards and return merged results.
     *
     * WARNING: This loads all results into memory. For large datasets,
     * use lazy() or cursor() instead to stream results.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    public function get(array $columns = ['*']): Collection
    {
        $results = new Collection;

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($columns, &$results): void {
            $shardResults = $clone->get($columns);
            $results = $results->merge($shardResults);
        });

        return $results;
    }

    /**
     * Get a lazy collection that streams results from all shards.
     *
     * This is memory-efficient for large datasets as it yields results
     * one at a time without loading everything into memory.
     *
     * @param  array<int, string>  $columns
     * @return LazyCollection<int, TModel>
     */
    public function lazy(array $columns = ['*']): LazyCollection
    {
        $shards = $this->targetShards;
        $builder = $this->builder;

        return LazyCollection::make(function () use ($shards, $columns, $builder) {
            foreach ($shards ?? [] as $shard) {
                yield from shardwise()->run($shard, function () use ($columns, $builder): LazyCollection {
                    /** @var Builder<TModel> $clone */
                    $clone = $builder->clone();
                    $clone->select($columns);

                    return $clone->lazy(1000);
                });
            }
        });
    }

    /**
     * Get a cursor that streams results from all shards.
     *
     * Similar to lazy() but uses database cursors for even better
     * memory efficiency with very large datasets.
     *
     * @param  array<int, string>  $columns
     * @return LazyCollection<int, TModel>
     */
    public function cursor(array $columns = ['*']): LazyCollection
    {
        $shards = $this->targetShards;
        $builder = $this->builder;

        return LazyCollection::make(function () use ($shards, $columns, $builder) {
            foreach ($shards ?? [] as $shard) {
                yield from shardwise()->run($shard, function () use ($columns, $builder): LazyCollection {
                    /** @var Builder<TModel> $clone */
                    $clone = $builder->clone();
                    $clone->select($columns);

                    return $clone->cursor();
                });
            }
        });
    }

    /**
     * Chunk results from all shards and process them in batches.
     *
     * Memory-efficient way to process large cross-shard datasets.
     *
     * @param  callable(Collection<int, TModel>, ShardInterface): mixed  $callback
     */
    public function chunk(int $chunkSize, callable $callback): bool
    {
        foreach ($this->targetShards ?? [] as $shard) {
            $shouldContinue = shardwise()->run($shard, function () use ($chunkSize, $callback, $shard): bool {
                $clone = $this->cloneBuilder();

                return $clone->chunk($chunkSize, function (BaseCollection $chunk, int $page) use ($callback, $shard): bool {
                    /** @var Collection<int, TModel> $chunk */
                    $result = $callback($chunk, $shard);

                    return $result !== false;
                });
            });

            if ($shouldContinue === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Chunk results by ID from all shards.
     *
     * More efficient than chunk() for tables with auto-incrementing IDs.
     *
     * @param  callable(Collection<int, TModel>, ShardInterface): mixed  $callback
     */
    public function chunkById(int $chunkSize, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        foreach ($this->targetShards ?? [] as $shard) {
            $shouldContinue = shardwise()->run($shard, function () use ($chunkSize, $callback, $column, $alias, $shard): bool {
                $clone = $this->cloneBuilder();

                return $clone->chunkById($chunkSize, function (BaseCollection $chunk, int $page) use ($callback, $shard): bool {
                    /** @var Collection<int, TModel> $chunk */
                    $result = $callback($chunk, $shard);

                    return $result !== false;
                }, $column, $alias);
            });

            if ($shouldContinue === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a callback for each model from all shards.
     *
     * Memory-efficient iteration over all models.
     *
     * @param  callable(TModel, ShardInterface): mixed  $callback
     */
    public function each(callable $callback, int $chunkSize = 1000): bool
    {
        return $this->chunk($chunkSize, function (Collection $chunk, ShardInterface $shard) use ($callback): bool {
            foreach ($chunk as $model) {
                if ($callback($model, $shard) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get the count from all shards.
     */
    public function count(string $column = '*'): int
    {
        $total = 0;

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($column, &$total): void {
            $total += $clone->count($column);
        });

        return $total;
    }

    /**
     * Get the sum from all shards.
     */
    public function sum(string $column): float
    {
        $total = 0.0;

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($column, &$total): void {
            /** @var int|float $shardSum */
            $shardSum = $clone->sum($column);
            $total += $shardSum;
        });

        return $total;
    }

    /**
     * Get the average from all shards.
     */
    public function avg(string $column): float
    {
        $sum = 0.0;
        $count = 0;

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($column, &$sum, &$count): void {
            /** @var int|float $shardSum */
            $shardSum = $clone->sum($column);
            $shardCount = $clone->count();

            $sum += $shardSum;
            $count += $shardCount;
        });

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Get the minimum from all shards.
     */
    public function min(string $column): mixed
    {
        $values = [];

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($column, &$values): void {
            $value = $clone->min($column);
            if ($value !== null) {
                $values[] = $value;
            }
        });

        return $values === [] ? null : min($values);
    }

    /**
     * Get the maximum from all shards.
     */
    public function max(string $column): mixed
    {
        $values = [];

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($column, &$values): void {
            $value = $clone->max($column);
            if ($value !== null) {
                $values[] = $value;
            }
        });

        return $values === [] ? null : max($values);
    }

    /**
     * Check if any records exist on any shard.
     */
    public function exists(): bool
    {
        foreach ($this->targetShards ?? [] as $shard) {
            $exists = shardwise()->run($shard, fn (): bool => $this->cloneBuilder()->exists());

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get results grouped by shard.
     *
     * @param  array<int, string>  $columns
     * @return BaseCollection<string, Collection<int, TModel>>
     */
    public function getGroupedByShard(array $columns = ['*']): BaseCollection
    {
        /** @var BaseCollection<string, Collection<int, TModel>> $grouped */
        $grouped = collect();

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($columns, &$grouped): void {
            $results = $clone->get($columns);
            $grouped->put($shard->getId(), $results);
        });

        return $grouped;
    }

    /**
     * Get counts grouped by shard.
     *
     * @return BaseCollection<string, int>
     */
    public function countGroupedByShard(string $column = '*'): BaseCollection
    {
        /** @var BaseCollection<string, int> $grouped */
        $grouped = collect();

        $this->executeOnShards(function (ShardInterface $shard, Builder $clone) use ($column, &$grouped): void {
            $count = $clone->count($column);
            $grouped->put($shard->getId(), $count);
        });

        return $grouped;
    }

    /**
     * Get the results collected from each shard.
     *
     * @return array<string, mixed>
     */
    public function getShardResults(): array
    {
        return $this->shardResults;
    }

    /**
     * Create a deep clone of the builder to avoid shared connection/query state between shards.
     *
     * @return Builder<TModel>
     */
    private function cloneBuilder(): Builder
    {
        /** @var Builder<TModel> $clone */
        $clone = $this->builder->clone();

        return $clone;
    }

    /**
     * Execute a callback on each target shard.
     *
     * A deep-cloned builder is passed to the callback to prevent shared
     * connection/query state between shard iterations.
     *
     * @param  callable(ShardInterface, Builder<TModel>): mixed  $callback
     */
    private function executeOnShards(callable $callback): void
    {
        $shardCount = $this->targetShards?->count() ?? 0;

        // Log scatter query warning (only once per builder instance)
        if (! $this->scatterLogged && $shardCount > 1) {
            $this->scatterLogged = true;
            self::logScatterQuery(
                $this->builder->toSql(),
                $this->builder->getBindings(),
                $shardCount
            );
        }

        foreach ($this->targetShards ?? [] as $shard) {
            shardwise()->run($shard, function () use ($shard, $callback): void {
                // Deep-clone the builder for each shard to avoid shared connection state
                $clone = $this->cloneBuilder();
                $result = $callback($shard, $clone);
                $this->shardResults[$shard->getId()] = $result;
            });
        }
    }
}

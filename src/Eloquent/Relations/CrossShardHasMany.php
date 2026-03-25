<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Cross-shard hasMany relationship.
 *
 * Queries a sharded model across all active shards from a central model.
 * This is not a standard Eloquent Relation — it provides a fluent,
 * method-based API for querying related sharded records.
 *
 * Usage:
 *   $agent->tickets()->get();
 *   $agent->tickets()->where('status', 'open')->count();
 *   $agent->tickets()->orderBy('created_at', 'desc')->limit(10)->get();
 *
 * @template TRelated of Model
 */
final class CrossShardHasMany
{
    /**
     * Queued where constraints to apply on each shard query.
     *
     * @var array<int, array{type: string, args: array<int, mixed>}>
     */
    private array $wheres = [];

    /**
     * Queued orderBy clauses to apply globally after merging results.
     *
     * @var array<int, array{column: string, direction: string}>
     */
    private array $orders = [];

    /**
     * Global limit to apply after merging results from all shards.
     */
    private ?int $globalLimit = null;

    /**
     * Global offset to apply after merging results from all shards.
     */
    private ?int $globalOffset = null;

    /**
     * Queued select columns.
     *
     * @var array<int, string>|null
     */
    private ?array $selectColumns = null;

    /**
     * Queued withCount relations.
     *
     * @var array<int, string>
     */
    private array $withCounts = [];

    /**
     * Queued eager loads.
     *
     * @var array<int, string>
     */
    private array $eagerLoads = [];

    /**
     * Create a new cross-shard hasMany relationship instance.
     *
     * @param  Model  $parent  The central model that owns the relationship.
     * @param  class-string<TRelated>  $related  The fully qualified class name of the sharded model.
     * @param  string  $foreignKey  The foreign key on the sharded model.
     * @param  string  $localKey  The local key on the central model.
     */
    public function __construct(
        private readonly Model $parent,
        private readonly string $related,
        private readonly string $foreignKey,
        private readonly string $localKey,
    ) {}

    /**
     * Get all related models across all active shards.
     *
     * Results are merged from all shards, then global ordering,
     * offset, and limit are applied.
     *
     * @param  array<int, string>|string  $columns
     * @return Collection<int, TRelated>
     */
    public function get(array|string $columns = ['*']): Collection
    {
        $columns = is_array($columns) ? $columns : [$columns];

        return $this->executeAcrossShards(function (string $relatedClass, string $foreignKey, mixed $parentKey) use ($columns): Collection {
            $query = $relatedClass::where($foreignKey, $parentKey);
            $this->applyConstraints($query);
            $this->applyEagerLoads($query);

            if ($this->selectColumns !== null) {
                $query->select($this->selectColumns);
            }

            return $query->get($columns);
        });
    }

    /**
     * Get the total count of related models across all active shards.
     */
    public function count(): int
    {
        $parentKey = $this->getParentKey();
        $total = 0;

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $total += shardwise()->run($shard, function () use ($parentKey): int {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);

                    return $query->count();
                });
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return $total;
    }

    /**
     * Check if any related models exist across all active shards.
     *
     * Short-circuits on the first shard that returns a match.
     */
    public function exists(): bool
    {
        $parentKey = $this->getParentKey();

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $found = shardwise()->run($shard, function () use ($parentKey): bool {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);

                    return $query->exists();
                });

                if ($found) {
                    return true;
                }
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return false;
    }

    /**
     * Get the first matching related model from any shard.
     *
     * When ordering is specified, results from all shards are merged
     * and the first one according to the order is returned. Without
     * ordering, the first match from any shard is returned.
     *
     * @return TRelated|null
     */
    public function first(): ?Model
    {
        if (! empty($this->orders)) {
            return $this->limit(1)->get()->first();
        }

        $parentKey = $this->getParentKey();

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $result = shardwise()->run($shard, function () use ($parentKey): ?Model {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);
                    $this->applyEagerLoads($query);

                    if ($this->selectColumns !== null) {
                        $query->select($this->selectColumns);
                    }

                    return $query->first();
                });

                if ($result !== null) {
                    return $result;
                }
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return null;
    }

    /**
     * Pluck a single column's values from all shards.
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): \Illuminate\Support\Collection
    {
        $parentKey = $this->getParentKey();
        $results = collect();

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $shardResults = shardwise()->run($shard, function () use ($parentKey, $column, $key): \Illuminate\Support\Collection {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);

                    return $query->pluck($column, $key);
                });

                $results = $results->merge($shardResults);
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return $results;
    }

    /**
     * Get the sum of a column across all shards.
     */
    public function sum(string $column): float|int
    {
        $parentKey = $this->getParentKey();
        $total = 0;

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $total += shardwise()->run($shard, function () use ($parentKey, $column): float|int {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);

                    return $query->sum($column);
                });
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return $total;
    }

    /**
     * Get the minimum value of a column across all shards.
     */
    public function min(string $column): mixed
    {
        $parentKey = $this->getParentKey();
        $values = [];

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $value = shardwise()->run($shard, function () use ($parentKey, $column): mixed {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);

                    return $query->min($column);
                });

                if ($value !== null) {
                    $values[] = $value;
                }
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return empty($values) ? null : min($values);
    }

    /**
     * Get the maximum value of a column across all shards.
     */
    public function max(string $column): mixed
    {
        $parentKey = $this->getParentKey();
        $values = [];

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $value = shardwise()->run($shard, function () use ($parentKey, $column): mixed {
                    $query = $this->related::where($this->foreignKey, $parentKey);
                    $this->applyConstraints($query);

                    return $query->max($column);
                });

                if ($value !== null) {
                    $values[] = $value;
                }
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return empty($values) ? null : max($values);
    }

    /**
     * Add a where constraint to the relationship query.
     *
     * Supports the same signatures as Laravel's Eloquent where():
     *   ->where('column', 'value')
     *   ->where('column', 'operator', 'value')
     *   ->where(['column' => 'value'])
     *   ->where(callback)
     *
     * @return $this
     */
    public function where(mixed ...$args): static
    {
        $this->wheres[] = ['type' => 'where', 'args' => $args];

        return $this;
    }

    /**
     * Add a whereIn constraint to the relationship query.
     *
     * @param  array<int, mixed>  $values
     * @return $this
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'whereIn', 'args' => [$column, $values]];

        return $this;
    }

    /**
     * Add a whereNotIn constraint to the relationship query.
     *
     * @param  array<int, mixed>  $values
     * @return $this
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'whereNotIn', 'args' => [$column, $values]];

        return $this;
    }

    /**
     * Add a whereNull constraint to the relationship query.
     *
     * @return $this
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'whereNull', 'args' => [$column]];

        return $this;
    }

    /**
     * Add a whereNotNull constraint to the relationship query.
     *
     * @return $this
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'whereNotNull', 'args' => [$column]];

        return $this;
    }

    /**
     * Add a whereBetween constraint to the relationship query.
     *
     * @param  array{0: mixed, 1: mixed}  $values
     * @return $this
     */
    public function whereBetween(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'whereBetween', 'args' => [$column, $values]];

        return $this;
    }

    /**
     * Add an orWhere constraint to the relationship query.
     *
     * @return $this
     */
    public function orWhere(mixed ...$args): static
    {
        $this->wheres[] = ['type' => 'orWhere', 'args' => $args];

        return $this;
    }

    /**
     * Add a global orderBy clause.
     *
     * Ordering is applied globally after merging results from all shards,
     * ensuring correct sort order across the full result set.
     *
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = ['column' => $column, 'direction' => strtolower($direction)];

        return $this;
    }

    /**
     * Add a descending global orderBy clause.
     *
     * @return $this
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add a latest (descending by date) global orderBy clause.
     *
     * @return $this
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderByDesc($column);
    }

    /**
     * Add an oldest (ascending by date) global orderBy clause.
     *
     * @return $this
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Set the global limit on the merged result set.
     *
     * @return $this
     */
    public function limit(int $limit): static
    {
        $this->globalLimit = $limit;

        return $this;
    }

    /**
     * Alias for limit().
     *
     * @return $this
     */
    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    /**
     * Set the global offset on the merged result set.
     *
     * @return $this
     */
    public function offset(int $offset): static
    {
        $this->globalOffset = $offset;

        return $this;
    }

    /**
     * Alias for offset().
     *
     * @return $this
     */
    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    /**
     * Set the columns to select.
     *
     * @param  array<int, string>|string  ...$columns
     * @return $this
     */
    public function select(string ...$columns): static
    {
        $this->selectColumns = $columns;

        return $this;
    }

    /**
     * Set relations to eager load on each shard query.
     *
     * @return $this
     */
    public function with(string ...$relations): static
    {
        $this->eagerLoads = array_merge($this->eagerLoads, $relations);

        return $this;
    }

    /**
     * Add withCount relations to each shard query.
     *
     * @return $this
     */
    public function withCount(string ...$relations): static
    {
        $this->withCounts = array_merge($this->withCounts, $relations);

        return $this;
    }

    /**
     * Create a new related model on its appropriate shard.
     *
     * The foreign key is automatically set to the parent's key value.
     *
     * @param  array<string, mixed>  $attributes
     * @return TRelated
     */
    public function create(array $attributes = []): Model
    {
        $attributes[$this->foreignKey] = $this->getParentKey();

        return $this->related::create($attributes);
    }

    /**
     * Get the parent model's local key value.
     */
    public function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Get the foreign key name.
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the related model class name.
     *
     * @return class-string<TRelated>
     */
    public function getRelated(): string
    {
        return $this->related;
    }

    /**
     * Execute a query callback across all active shards and merge the results.
     *
     * Applies global ordering, offset, and limit after merging.
     *
     * @param  callable(class-string<TRelated>, string, mixed): Collection<int, TRelated>  $callback
     * @return Collection<int, TRelated>
     */
    private function executeAcrossShards(callable $callback): Collection
    {
        $parentKey = $this->getParentKey();
        $results = new Collection;

        foreach (shardwise()->getShards()->active() as $shard) {
            try {
                $shardResults = shardwise()->run($shard, fn (): Collection => $callback(
                    $this->related,
                    $this->foreignKey,
                    $parentKey,
                ));

                $results = $results->merge($shardResults);
            } catch (Throwable $e) {
                if ($this->shouldTolerateDeadShard()) {
                    continue;
                }

                throw $e;
            }
        }

        return $this->applyGlobalModifiers($results);
    }

    /**
     * Apply queued where constraints to an Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TRelated>  $query
     */
    private function applyConstraints(mixed $query): void
    {
        foreach ($this->wheres as $where) {
            $query->{$where['type']}(...$where['args']);
        }

        if (! empty($this->withCounts)) {
            $query->withCount($this->withCounts);
        }
    }

    /**
     * Apply queued eager loads to an Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TRelated>  $query
     */
    private function applyEagerLoads(mixed $query): void
    {
        if (! empty($this->eagerLoads)) {
            $query->with($this->eagerLoads);
        }
    }

    /**
     * Apply global ordering, offset, and limit to merged results.
     *
     * @param  Collection<int, TRelated>  $results
     * @return Collection<int, TRelated>
     */
    private function applyGlobalModifiers(Collection $results): Collection
    {
        if (! empty($this->orders)) {
            $results = $this->applyGlobalOrdering($results);
        }

        if ($this->globalOffset !== null) {
            $results = $results->slice($this->globalOffset)->values();
        }

        if ($this->globalLimit !== null) {
            $results = $results->take($this->globalLimit)->values();
        }

        return $results;
    }

    /**
     * Sort a collection according to the queued order clauses.
     *
     * @param  Collection<int, TRelated>  $results
     * @return Collection<int, TRelated>
     */
    private function applyGlobalOrdering(Collection $results): Collection
    {
        // Build a multi-sort comparator from all order clauses
        return $results->sort(function (Model $a, Model $b): int {
            foreach ($this->orders as $order) {
                $valueA = $a->getAttribute($order['column']);
                $valueB = $b->getAttribute($order['column']);

                $comparison = $valueA <=> $valueB;

                if ($order['direction'] === 'desc') {
                    $comparison = -$comparison;
                }

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        })->values();
    }

    /**
     * Determine whether dead shard errors should be silently skipped.
     */
    private function shouldTolerateDeadShard(): bool
    {
        return (bool) config('shardwise.dead_shard_tolerance', false);
    }
}

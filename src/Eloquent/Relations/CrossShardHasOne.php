<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Cross-shard hasOne relationship.
 *
 * Queries a sharded model across all active shards from a central model,
 * returning only a single related model. If the related model exists on
 * multiple shards (which should not happen in a well-designed system),
 * the first match found is returned.
 *
 * Usage:
 *   $agent->activeSession()->get();
 *   $agent->activeSession()->where('active', true)->get();
 *
 * @template TRelated of Model
 */
final class CrossShardHasOne
{
    /**
     * Queued where constraints to apply on each shard query.
     *
     * @var array<int, array{type: string, args: array<int, mixed>}>
     */
    private array $wheres = [];

    /**
     * Queued eager loads.
     *
     * @var array<int, string>
     */
    private array $eagerLoads = [];

    /**
     * Queued select columns.
     *
     * @var array<int, string>|null
     */
    private ?array $selectColumns = null;

    /**
     * Create a new cross-shard hasOne relationship instance.
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
     * Get the related model from across all active shards.
     *
     * Short-circuits on the first shard that contains the related model.
     *
     * @return TRelated|null
     */
    public function get(): ?Model
    {
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
     * Check if the related model exists on any shard.
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
     * Add a where constraint to the relationship query.
     *
     * Supports the same signatures as Laravel's Eloquent where().
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
     * Set the columns to select.
     *
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
     * Apply queued where constraints to an Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TRelated>  $query
     */
    private function applyConstraints(mixed $query): void
    {
        foreach ($this->wheres as $where) {
            $query->{$where['type']}(...$where['args']);
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
     * Determine whether dead shard errors should be silently skipped.
     */
    private function shouldTolerateDeadShard(): bool
    {
        return (bool) config('shardwise.dead_shard_tolerance', false);
    }
}

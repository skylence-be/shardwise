<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Skylence\Shardwise\Contracts\ShardableInterface;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Query\CrossShardBuilder;
use Skylence\Shardwise\Uuid\UuidShardDecoder;

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
     * Get results from all shards and merge them.
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

        foreach ($shards as $shard) {
            $shardResults = shardwise()->run($shard, function () use ($columns): Collection {
                // Deep-clone the builder (including underlying query) to avoid shared connection state
                $clone = $this->clone();
                $clone->allShards = false;

                // Call get() on the clone, which will now use parent::get() since allShards is false
                return $clone->get($columns);
            });

            $results = $results->merge($shardResults);
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
            $count = shardwise()->run($shard, function () use ($columns): int {
                // Deep-clone the builder (including underlying query) to avoid shared connection state
                $clone = $this->clone();
                $clone->allShards = false;

                // Call count() on the clone, which will now use parent::count() since allShards is false
                return $clone->count($columns);
            });

            $total += $count;
        }

        return $total;
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

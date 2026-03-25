<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Query;

use Illuminate\Database\Query\Builder;
use Skylence\Shardwise\Contracts\ShardInterface;
use stdClass;

/**
 * Shard-aware query builder for raw database queries.
 */
final class ShardableQueryBuilder extends Builder
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
        $this->connection = app('db')->connection($shard->getConnectionName());

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
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    public function get($columns = ['*']): \Illuminate\Support\Collection
    {
        if ($this->allShards) {
            return $this->getFromAllShards($columns);
        }

        return parent::get($columns);
    }

    /**
     * Get the count of matching records.
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
     * Get the sum of a column's values from all shards.
     *
     * @param  string  $column
     */
    public function sum($column): int|float
    {
        if ($this->allShards) {
            return $this->sumFromAllShards($column);
        }

        return parent::sum($column);
    }

    /**
     * Create a deep clone of this query builder, ensuring connection, grammar,
     * and processor are not shared with the original instance.
     */
    private function deepClone(): self
    {
        $clone = clone $this;
        $clone->connection = clone $this->connection;
        $clone->grammar = clone $this->grammar;
        $clone->processor = clone $this->processor;

        return $clone;
    }

    /**
     * Get results from all shards and merge them.
     *
     * @param  array<int, string>|string  $columns
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function getFromAllShards(array|string $columns = ['*']): \Illuminate\Support\Collection
    {
        /** @var array<int, string> $columns */
        $columns = is_array($columns) ? $columns : func_get_args();

        $results = collect();

        $shards = shardwise()->getShards()->active();

        foreach ($shards as $shard) {
            $shardResults = shardwise()->run($shard, function () use ($columns) {
                // Deep-clone the query to avoid shared connection/grammar/processor state
                $clone = $this->deepClone();
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
                // Deep-clone the query to avoid shared connection/grammar/processor state
                $clone = $this->deepClone();
                $clone->allShards = false;

                // Call count() on the clone, which will now use parent::count() since allShards is false
                return $clone->count($columns);
            });

            $total += $count;
        }

        return $total;
    }

    /**
     * Sum from all shards.
     */
    private function sumFromAllShards(string $column): int|float
    {
        $total = 0;

        $shards = shardwise()->getShards()->active();

        foreach ($shards as $shard) {
            $sum = shardwise()->run($shard, function () use ($column): int|float {
                // Deep-clone the query to avoid shared connection/grammar/processor state
                $clone = $this->deepClone();
                $clone->allShards = false;

                // Call sum() on the clone, which will now use parent::sum() since allShards is false
                return $clone->sum($column);
            });

            $total += $sum;
        }

        return $total;
    }
}

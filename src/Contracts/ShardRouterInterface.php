<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Contracts;

use Skylence\Shardwise\ShardCollection;

/**
 * Contract for query routing to shards.
 */
interface ShardRouterInterface
{
    /**
     * Route to the appropriate shard for the given key.
     */
    public function route(string|int $key): ShardInterface;

    /**
     * Route to the appropriate shard for a table and key.
     */
    public function routeForTable(string $table, string|int $key): ShardInterface;

    /**
     * Get the shard for a specific shard ID.
     */
    public function getShardById(string $shardId): ?ShardInterface;

    /**
     * Get all available shards.
     */
    public function getShards(): ShardCollection;

    /**
     * Get the current routing strategy.
     */
    public function getStrategy(): ShardStrategyInterface;

    /**
     * Set the routing strategy.
     */
    public function setStrategy(ShardStrategyInterface $strategy): void;
}

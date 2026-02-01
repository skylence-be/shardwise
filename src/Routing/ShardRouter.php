<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Routing;

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardRouterInterface;
use Skylence\Shardwise\Contracts\ShardStrategyInterface;
use Skylence\Shardwise\Exceptions\ShardNotFoundException;
use Skylence\Shardwise\ShardCollection;

/**
 * Routes queries to the appropriate shard based on the configured strategy.
 */
final class ShardRouter implements ShardRouterInterface
{
    public function __construct(
        private readonly ShardCollection $shards,
        private ShardStrategyInterface $strategy,
        private readonly TableGroupResolver $tableGroupResolver,
    ) {}

    /**
     * Route to the appropriate shard for the given key.
     */
    public function route(string|int $key): ShardInterface
    {
        return $this->strategy->getShard($key, $this->shards->active());
    }

    /**
     * Route to the appropriate shard for a table and key.
     */
    public function routeForTable(string $table, string|int $key): ShardInterface
    {
        // Get the effective routing key based on table group
        $groupName = $this->tableGroupResolver->getGroupForTable($table);

        if ($groupName !== null) {
            // If the table belongs to a group, use the group name as part of the routing
            // This ensures all tables in the same group route together
            $key = "{$groupName}:{$key}";
        }

        return $this->route($key);
    }

    /**
     * Get the shard for a specific shard ID.
     *
     * @throws ShardNotFoundException
     */
    public function getShardById(string $shardId): ?ShardInterface
    {
        return $this->shards->get($shardId);
    }

    /**
     * Get all available shards.
     */
    public function getShards(): ShardCollection
    {
        return $this->shards;
    }

    /**
     * Get the current routing strategy.
     */
    public function getStrategy(): ShardStrategyInterface
    {
        return $this->strategy;
    }

    /**
     * Set the routing strategy.
     */
    public function setStrategy(ShardStrategyInterface $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * Get the table group resolver.
     */
    public function getTableGroupResolver(): TableGroupResolver
    {
        return $this->tableGroupResolver;
    }
}

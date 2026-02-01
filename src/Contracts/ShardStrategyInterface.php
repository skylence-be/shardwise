<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Contracts;

use Skylence\Shardwise\ShardCollection;

/**
 * Defines how to route a key to a specific shard.
 */
interface ShardStrategyInterface
{
    /**
     * Get the shard for the given key.
     */
    public function getShard(string|int $key, ShardCollection $shards): ShardInterface;

    /**
     * Get the strategy name identifier.
     */
    public function getName(): string;

    /**
     * Check if this strategy supports the given key type.
     */
    public function supportsKey(mixed $key): bool;
}

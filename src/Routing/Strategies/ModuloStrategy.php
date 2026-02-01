<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Routing\Strategies;

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardStrategyInterface;
use Skylence\Shardwise\Exceptions\ShardingException;
use Skylence\Shardwise\ShardCollection;

/**
 * Simple modulo-based routing strategy.
 *
 * Routes keys based on key % shard_count. Simple and fast but
 * does not handle shard addition/removal gracefully.
 */
final class ModuloStrategy implements ShardStrategyInterface
{
    /**
     * Get the shard for the given key.
     *
     * @throws ShardingException
     */
    public function getShard(string|int $key, ShardCollection $shards): ShardInterface
    {
        if ($shards->isEmpty()) {
            throw ShardingException::noActiveShards();
        }

        $numericKey = $this->normalizeKey($key);
        $shardCount = $shards->count();

        $index = $numericKey % $shardCount;

        $shardIds = $shards->ids();
        sort($shardIds); // Ensure deterministic ordering
        $selectedShardId = $shardIds[$index];

        $shard = $shards->get($selectedShardId);

        if ($shard === null) {
            throw ShardingException::noActiveShards();
        }

        return $shard;
    }

    /**
     * Get the strategy name identifier.
     */
    public function getName(): string
    {
        return 'modulo';
    }

    /**
     * Check if this strategy supports the given key type.
     */
    public function supportsKey(mixed $key): bool
    {
        return is_string($key) || is_int($key);
    }

    /**
     * Normalize the key to a non-negative integer.
     */
    private function normalizeKey(string|int $key): int
    {
        if (is_int($key)) {
            return abs($key);
        }

        // For string keys, use a hash to convert to numeric
        return abs((int) crc32($key));
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Routing\Strategies;

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardStrategyInterface;
use Skylence\Shardwise\Exceptions\ShardingException;
use Skylence\Shardwise\ShardCollection;

/**
 * Routing strategy based on key ranges.
 *
 * Each shard handles a specific range of keys. Ranges are defined
 * in the shard metadata as 'range_start' and 'range_end'.
 */
final class RangeStrategy implements ShardStrategyInterface
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

        foreach ($shards as $shard) {
            $metadata = $shard->getMetadata();
            $rangeStart = $metadata['range_start'] ?? null;
            $rangeEnd = $metadata['range_end'] ?? null;

            if ($rangeStart === null || $rangeEnd === null) {
                continue;
            }

            if ($numericKey >= $rangeStart && $numericKey < $rangeEnd) {
                return $shard;
            }
        }

        // If no range matches, use the last shard (catch-all for overflow)
        $lastShard = null;
        foreach ($shards as $shard) {
            $lastShard = $shard;
        }

        if ($lastShard === null) {
            throw ShardingException::noActiveShards();
        }

        return $lastShard;
    }

    /**
     * Get the strategy name identifier.
     */
    public function getName(): string
    {
        return 'range';
    }

    /**
     * Check if this strategy supports the given key type.
     */
    public function supportsKey(mixed $key): bool
    {
        return is_string($key) || is_int($key);
    }

    /**
     * Normalize the key to a numeric value.
     */
    private function normalizeKey(string|int $key): int
    {
        if (is_int($key)) {
            return $key;
        }

        // For string keys, use a hash to convert to numeric
        return abs((int) crc32($key));
    }
}

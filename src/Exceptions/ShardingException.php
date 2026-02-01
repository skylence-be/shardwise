<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Exceptions;

use Exception;

final class ShardingException extends Exception
{
    public static function noActiveShards(): self
    {
        return new self('No active shards available for routing.');
    }

    public static function noShardContext(): self
    {
        return new self('No shard context is currently active.');
    }

    public static function invalidShardKey(mixed $key): self
    {
        $type = get_debug_type($key);

        return new self("Invalid shard key type: {$type}. Expected string or int.");
    }

    public static function strategyNotFound(string $strategy): self
    {
        return new self("Shard routing strategy '{$strategy}' not found.");
    }

    public static function connectionFailed(string $shardId, string $message): self
    {
        return new self("Failed to connect to shard '{$shardId}': {$message}");
    }

    /**
     * @param  array<int, string>  $shards
     */
    public static function crossShardTransaction(array $shards): self
    {
        return new self(sprintf(
            'Cross-shard transaction detected: Operations span shards [%s]. '.
            'Cross-shard transactions are NOT atomic. Use the Saga pattern for distributed consistency.',
            implode(', ', $shards)
        ));
    }
}

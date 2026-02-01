<?php

declare(strict_types=1);

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardContext;
use Skylence\Shardwise\ShardwiseManager;

if (! function_exists('shardwise')) {
    /**
     * Get the Shardwise manager instance.
     */
    function shardwise(): ShardwiseManager
    {
        return app(ShardwiseManager::class);
    }
}

if (! function_exists('shard')) {
    /**
     * Get the current shard or a specific shard by ID.
     */
    function shard(?string $shardId = null): ?ShardInterface
    {
        if ($shardId !== null) {
            return shardwise()->getShard($shardId);
        }

        return ShardContext::current();
    }
}

if (! function_exists('on_shard')) {
    /**
     * Execute a callback within a specific shard context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    function on_shard(ShardInterface|string $shard, callable $callback): mixed
    {
        return shardwise()->run($shard, $callback);
    }
}

if (! function_exists('on_all_shards')) {
    /**
     * Execute a callback on all active shards.
     *
     * @template T
     *
     * @param  callable(ShardInterface): T  $callback
     * @return array<string, T>
     */
    function on_all_shards(callable $callback): array
    {
        return shardwise()->runOnAllShards($callback);
    }
}

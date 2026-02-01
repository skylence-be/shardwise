<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardRouterInterface;

/**
 * Main entry point for Shardwise functionality.
 *
 * This class is a lightweight wrapper around ShardwiseManager
 * that provides a clean API for shard operations.
 *
 * @see ShardwiseManager
 */
final class Shardwise
{
    public function __construct(
        private readonly ShardwiseManager $manager,
    ) {}

    /**
     * Initialize shard context.
     */
    public function initialize(ShardInterface|string $shard): void
    {
        $this->manager->initialize($shard);
    }

    /**
     * End the current shard context.
     */
    public function end(): void
    {
        $this->manager->end();
    }

    /**
     * Execute a callback within a shard context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(ShardInterface|string $shard, callable $callback): mixed
    {
        return $this->manager->run($shard, $callback);
    }

    /**
     * Execute a callback on all shards.
     *
     * @template T
     *
     * @param  callable(ShardInterface): T  $callback
     * @return array<string, T>
     */
    public function runOnAllShards(callable $callback): array
    {
        return $this->manager->runOnAllShards($callback);
    }

    /**
     * Get the current shard.
     */
    public function current(): ?ShardInterface
    {
        return $this->manager->current();
    }

    /**
     * Check if there's an active shard context.
     */
    public function active(): bool
    {
        return $this->manager->active();
    }

    /**
     * Get a shard by ID.
     */
    public function getShard(string $shardId): ShardInterface
    {
        return $this->manager->getShard($shardId);
    }

    /**
     * Get all configured shards.
     */
    public function getShards(): ShardCollection
    {
        return $this->manager->getShards();
    }

    /**
     * Get the router instance.
     */
    public function getRouter(): ShardRouterInterface
    {
        return $this->manager->getRouter();
    }

    /**
     * Route a key to the appropriate shard.
     */
    public function route(string|int $key): ShardInterface
    {
        return $this->manager->route($key);
    }

    /**
     * Route a key for a specific table.
     */
    public function routeForTable(string $table, string|int $key): ShardInterface
    {
        return $this->manager->routeForTable($table, $key);
    }

    /**
     * Get the underlying manager.
     */
    public function getManager(): ShardwiseManager
    {
        return $this->manager;
    }
}

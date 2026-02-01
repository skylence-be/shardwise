<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Contracts;

/**
 * Contract for shard context bootstrappers.
 *
 * Bootstrappers are responsible for setting up and tearing down
 * shard-specific context (database connections, cache prefixes, etc.).
 */
interface BootstrapperInterface
{
    /**
     * Bootstrap the shard context.
     *
     * This method is called when entering a shard context.
     */
    public function bootstrap(ShardInterface $shard): void;

    /**
     * Revert the shard context.
     *
     * This method is called when exiting a shard context.
     * Bootstrappers are reverted in reverse order of initialization.
     */
    public function revert(): void;
}

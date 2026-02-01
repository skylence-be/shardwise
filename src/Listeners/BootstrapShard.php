<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Listeners;

use Skylence\Shardwise\Events\ShardInitialized;

/**
 * Listener that runs bootstrappers when a shard is initialized.
 *
 * This listener is typically not needed as ShardwiseManager handles
 * bootstrapping internally. It's provided for custom event-driven workflows.
 */
final class BootstrapShard
{
    /**
     * Handle the shard initialized event.
     */
    public function handle(ShardInitialized $event): void
    {
        // Bootstrapping is handled internally by ShardwiseManager
        // This listener can be extended for custom logic if needed
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Listeners;

use Skylence\Shardwise\Events\ShardEnded;

/**
 * Listener that reverts bootstrappers when a shard context ends.
 *
 * This listener is typically not needed as ShardwiseManager handles
 * reverting internally. It's provided for custom event-driven workflows.
 */
final class RevertFromShard
{
    /**
     * Handle the shard ended event.
     */
    public function handle(ShardEnded $event): void
    {
        // Reverting is handled internally by ShardwiseManager
        // This listener can be extended for custom logic if needed
    }
}

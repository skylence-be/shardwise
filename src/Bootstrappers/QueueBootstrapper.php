<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Bootstrappers;

use Skylence\Shardwise\Contracts\BootstrapperInterface;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Bootstrapper placeholder for queue-related shard context.
 *
 * Note: Queue integration (payload injection, job context initialization, and cleanup)
 * is handled by the ShardwiseServiceProvider to ensure one-time registration.
 * This bootstrapper exists as an extension point for custom queue-related logic.
 */
final class QueueBootstrapper implements BootstrapperInterface
{
    /**
     * Bootstrap the shard context for queue operations.
     *
     * Queue listeners are registered once in ShardwiseServiceProvider.
     * Override this method in a custom bootstrapper for additional logic.
     */
    public function bootstrap(ShardInterface $shard): void
    {
        // Queue integration is handled by ShardwiseServiceProvider
    }

    /**
     * Revert the queue bootstrapper.
     */
    public function revert(): void
    {
        // Queue listeners remain registered for the application lifetime
    }
}

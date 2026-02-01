<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Queue;

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardContext;

/**
 * Trait for making jobs shard-aware.
 *
 * Apply this trait to jobs that need to execute in a specific shard context.
 */
trait ShardAwareJob
{
    /**
     * The shard ID this job should run on.
     */
    public ?string $shardId = null;

    /**
     * Set the shard for this job.
     *
     * @return $this
     */
    public function onShard(ShardInterface|string $shard): static
    {
        $this->shardId = $shard instanceof ShardInterface ? $shard->getId() : $shard;

        return $this;
    }

    /**
     * Get the shard ID for this job.
     */
    public function getShardId(): ?string
    {
        return $this->shardId;
    }

    /**
     * Initialize shard context before job execution.
     */
    protected function initializeShardContext(): void
    {
        if ($this->shardId !== null) {
            shardwise()->initialize($this->shardId);
        }
    }

    /**
     * End shard context after job execution.
     */
    protected function endShardContext(): void
    {
        if ($this->shardId !== null && ShardContext::active()) {
            shardwise()->end();
        }
    }

    /**
     * Execute the job within the shard context.
     *
     * Override the handle method and call this from your job's handle method.
     */
    protected function executeInShardContext(callable $callback): mixed
    {
        if ($this->shardId === null) {
            return $callback();
        }

        return shardwise()->run($this->shardId, $callback);
    }
}

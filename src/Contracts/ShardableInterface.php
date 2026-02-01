<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Contracts;

/**
 * Contract for models that support sharding.
 */
interface ShardableInterface
{
    /**
     * Get the column name used as the shard key.
     */
    public function getShardKeyColumn(): string;

    /**
     * Get the shard key value for this model instance.
     */
    public function getShardKeyValue(): string|int|null;

    /**
     * Get the table group this model belongs to.
     */
    public function getTableGroup(): ?string;

    /**
     * Determine the shard for this model instance.
     */
    public function resolveShard(): ?ShardInterface;
}

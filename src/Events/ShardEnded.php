<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Fired when a shard context ends, after bootstrappers are reverted.
 */
final readonly class ShardEnded
{
    use Dispatchable;

    public function __construct(
        public ShardInterface $shard,
    ) {}
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Fired after all bootstrappers have run for a shard.
 */
final readonly class ShardBootstrapped
{
    use Dispatchable;

    public function __construct(
        public ShardInterface $shard,
    ) {}
}

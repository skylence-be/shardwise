<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Exceptions;

use Exception;

final class ShardNotFoundException extends Exception
{
    public static function forId(string $shardId): self
    {
        return new self("Shard with ID '{$shardId}' not found.");
    }
}

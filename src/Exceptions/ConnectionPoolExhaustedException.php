<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Exceptions;

use RuntimeException;

final class ConnectionPoolExhaustedException extends RuntimeException
{
    public static function forConnection(string $connectionName, int $maxConnections): self
    {
        return new self(
            "Connection pool exhausted for shard connection '{$connectionName}'. Maximum {$maxConnections} connections reached."
        );
    }
}

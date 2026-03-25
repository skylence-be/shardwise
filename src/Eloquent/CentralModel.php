<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent;

use Skylence\Shardwise\Central;
use Skylence\Shardwise\ShardContext;

/**
 * Trait for models that should always use the central database connection.
 *
 * Use this trait on models that live in the central database (not sharded)
 * to ensure they are never affected by shard context switching. Without this,
 * models using the default connection will be redirected to the shard
 * connection when a shard context is active.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait CentralModel
{
    /**
     * Get the connection name, pinning to central when a shard context is active.
     *
     * When no shard context is active, this returns the model's existing
     * connection (or null for the default), preserving normal behavior
     * including in test environments. When a shard context is active,
     * it ensures the model uses the central connection.
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (ShardContext::current() !== null) {
            return Central::connectionName();
        }

        return null;
    }
}

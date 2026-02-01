<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Connections;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Skylence\Shardwise\ShardContext;

/**
 * Connection resolver that is aware of the current shard context.
 */
final class ShardConnectionResolver implements ConnectionResolverInterface
{
    public function __construct(
        private readonly ConnectionResolverInterface $baseResolver,
        private readonly ShardConnectionFactory $factory,
    ) {}

    /**
     * Get a database connection instance.
     *
     * @param  string|null  $name
     */
    public function connection($name = null): Connection
    {
        // If a specific connection is requested, use it
        if ($name !== null) {
            return $this->baseResolver->connection($name);
        }

        // If in a shard context, use the shard connection
        $shard = ShardContext::current();
        if ($shard !== null) {
            return $this->factory->make($shard);
        }

        // Otherwise, use the default connection
        return $this->baseResolver->connection($name);
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        // If in a shard context, return the shard's connection name
        $shard = ShardContext::current();
        if ($shard !== null) {
            return $shard->getConnectionName();
        }

        return $this->baseResolver->getDefaultConnection();
    }

    /**
     * Set the default connection name.
     *
     * @param  string  $name
     */
    public function setDefaultConnection($name): void
    {
        $this->baseResolver->setDefaultConnection($name);
    }
}

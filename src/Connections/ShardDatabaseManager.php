<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Connections;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardContext;

/**
 * Extended database manager with shard-aware connection handling.
 */
final class ShardDatabaseManager
{
    /** @var array<int, string> */
    private array $previousConnections = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ShardConnectionFactory $factory,
    ) {}

    /**
     * Connect to a specific shard.
     */
    public function connectToShard(ShardInterface $shard): Connection
    {
        $this->previousConnections[] = $this->database->getDefaultConnection();

        $connection = $this->factory->make($shard);

        $this->database->setDefaultConnection($shard->getConnectionName());

        return $connection;
    }

    /**
     * Disconnect from the current shard and restore the previous connection.
     */
    public function disconnectFromShard(): void
    {
        $previousConnection = array_pop($this->previousConnections);

        if ($previousConnection !== null) {
            $this->database->setDefaultConnection($previousConnection);
        }
    }

    /**
     * Get the connection for the current shard context.
     */
    public function shardConnection(): ?Connection
    {
        $shard = ShardContext::current();

        if ($shard === null) {
            return null;
        }

        return $this->factory->make($shard);
    }

    /**
     * Get the default database connection.
     */
    public function getDefaultConnection(): string
    {
        return $this->database->getDefaultConnection();
    }

    /**
     * Set the default database connection.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->database->setDefaultConnection($name);
    }

    /**
     * Get a database connection by name.
     */
    public function connection(?string $name = null): Connection
    {
        return $this->database->connection($name);
    }

    /**
     * Purge a database connection.
     */
    public function purge(?string $name = null): void
    {
        $this->database->purge($name);
    }

    /**
     * Reconnect to a database connection.
     */
    public function reconnect(?string $name = null): Connection
    {
        return $this->database->reconnect($name);
    }

    /**
     * Get the underlying database manager.
     */
    public function getManager(): DatabaseManager
    {
        return $this->database;
    }
}

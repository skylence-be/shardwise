<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Connections;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Factory for creating and caching shard database connections.
 */
final class ShardConnectionFactory
{
    /**
     * Cache of created connections.
     *
     * @var array<string, bool>
     */
    private array $configuredConnections = [];

    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Get or create a connection for the given shard.
     */
    public function make(ShardInterface $shard): Connection
    {
        $connectionName = $shard->getConnectionName();

        if (! isset($this->configuredConnections[$connectionName])) {
            $this->configureConnection($shard);
        }

        return $this->database->connection($connectionName);
    }

    /**
     * Configure a database connection for the shard.
     */
    public function configureConnection(ShardInterface $shard): void
    {
        $connectionName = $shard->getConnectionName();
        $config = $this->buildConnectionConfig($shard);

        config(["database.connections.{$connectionName}" => $config]);

        $this->configuredConnections[$connectionName] = true;
    }

    /**
     * Purge a shard connection.
     */
    public function purge(ShardInterface $shard): void
    {
        $connectionName = $shard->getConnectionName();

        $this->database->purge($connectionName);

        unset($this->configuredConnections[$connectionName]);
    }

    /**
     * Check if a connection has been configured.
     */
    public function hasConnection(ShardInterface $shard): bool
    {
        return isset($this->configuredConnections[$shard->getConnectionName()]);
    }

    /**
     * Get all configured connection names.
     *
     * @return array<string>
     */
    public function getConfiguredConnections(): array
    {
        return array_keys($this->configuredConnections);
    }

    /**
     * Purge all shard connections.
     */
    public function purgeAll(): void
    {
        foreach ($this->configuredConnections as $connectionName => $configured) {
            $this->database->purge($connectionName);
        }

        $this->configuredConnections = [];
    }

    /**
     * Build the connection configuration array for a shard.
     *
     * @return array<string, mixed>
     */
    private function buildConnectionConfig(ShardInterface $shard): array
    {
        $shardConfig = $shard->getConnectionConfig();

        // If the shard has a full config, use it
        if (isset($shardConfig['driver'])) {
            return $shardConfig;
        }

        // Otherwise, merge with central connection config
        /** @var string $centralConnection */
        $centralConnection = config('shardwise.central_connection', 'mysql');

        /** @var array<string, mixed> $baseConfig */
        $baseConfig = config("database.connections.{$centralConnection}", []);

        return array_merge($baseConfig, $shardConfig);
    }
}

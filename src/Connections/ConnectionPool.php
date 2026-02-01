<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Connections;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Connection pool for managing shard database connections with limits.
 */
final class ConnectionPool
{
    /**
     * Active connections count per shard.
     *
     * @var array<string, int>
     */
    private array $activeConnections = [];

    /**
     * Last access time per connection.
     *
     * @var array<string, int>
     */
    private array $lastAccess = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ShardConnectionFactory $factory,
        private readonly int $maxConnections = 10,
        private readonly int $idleTimeout = 60,
    ) {}

    /**
     * Acquire a connection from the pool.
     */
    public function acquire(ShardInterface $shard): Connection
    {
        $connectionName = $shard->getConnectionName();

        $this->cleanupIdleConnections();

        if ($this->getActiveCount($connectionName) >= $this->maxConnections) {
            $this->waitForConnection($connectionName);
        }

        $this->incrementActive($connectionName);
        $this->lastAccess[$connectionName] = time();

        return $this->factory->make($shard);
    }

    /**
     * Release a connection back to the pool.
     */
    public function release(ShardInterface $shard): void
    {
        $connectionName = $shard->getConnectionName();
        $this->decrementActive($connectionName);
    }

    /**
     * Get the active connection count for a shard.
     */
    public function getActiveCount(string $connectionName): int
    {
        return $this->activeConnections[$connectionName] ?? 0;
    }

    /**
     * Get total active connections across all shards.
     */
    public function getTotalActiveCount(): int
    {
        return array_sum($this->activeConnections);
    }

    /**
     * Get the maximum connections allowed per shard.
     */
    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    /**
     * Get the idle timeout in seconds.
     */
    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    /**
     * Cleanup idle connections that have exceeded the timeout.
     */
    public function cleanupIdleConnections(): void
    {
        $now = time();

        foreach ($this->lastAccess as $connectionName => $lastAccess) {
            if (($now - $lastAccess) > $this->idleTimeout && $this->getActiveCount($connectionName) === 0) {
                $this->database->purge($connectionName);
                unset($this->lastAccess[$connectionName]);
            }
        }
    }

    /**
     * Flush all connections from the pool.
     */
    public function flush(): void
    {
        foreach (array_keys($this->activeConnections) as $connectionName) {
            $this->database->purge($connectionName);
        }

        $this->activeConnections = [];
        $this->lastAccess = [];
    }

    /**
     * Get pool statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'active_connections' => $this->activeConnections,
            'total_active' => $this->getTotalActiveCount(),
            'max_per_shard' => $this->maxConnections,
            'idle_timeout' => $this->idleTimeout,
            'last_access' => $this->lastAccess,
        ];
    }

    private function incrementActive(string $connectionName): void
    {
        if (! isset($this->activeConnections[$connectionName])) {
            $this->activeConnections[$connectionName] = 0;
        }
        $this->activeConnections[$connectionName]++;
    }

    private function decrementActive(string $connectionName): void
    {
        if (isset($this->activeConnections[$connectionName]) && $this->activeConnections[$connectionName] > 0) {
            $this->activeConnections[$connectionName]--;
        }
    }

    private function waitForConnection(string $connectionName): void
    {
        // In a real implementation, this would wait for a connection to be released
        // For now, we'll just cleanup and try again
        $this->cleanupIdleConnections();

        // If still at max, force release the oldest
        if ($this->getActiveCount($connectionName) >= $this->maxConnections) {
            $this->database->purge($connectionName);
            $this->activeConnections[$connectionName] = 0;
        }
    }
}

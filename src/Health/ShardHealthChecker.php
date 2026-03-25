<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Health;

use Illuminate\Database\DatabaseManager;
use PDO;
use Skylence\Shardwise\Connections\ShardConnectionFactory;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardCollection;
use Throwable;

/**
 * Checks the health of database shards.
 */
final class ShardHealthChecker
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ShardConnectionFactory $connectionFactory,
    ) {}

    /**
     * Check the health of a single shard.
     */
    public function check(ShardInterface $shard): HealthCheckResult
    {
        $startTime = microtime(true);

        try {
            // Configure the connection if needed
            $this->connectionFactory->configureConnection($shard);

            $connection = $this->database->connection($shard->getConnectionName());

            /** @var string $query */
            $query = config('shardwise.health.query', 'SELECT 1');

            /** @var int $timeout */
            $timeout = config('shardwise.health.timeout', 5);

            // Set a timeout on the connection (driver-agnostic)
            try {
                $connection->getPdo()->setAttribute(PDO::ATTR_TIMEOUT, $timeout);
            } catch (Throwable) {
                // Some drivers may not support PDO::ATTR_TIMEOUT
            }

            // Execute health check query
            $connection->select($query);

            $latency = (microtime(true) - $startTime) * 1000;

            return HealthCheckResult::healthy($shard, (int) $latency);
        } catch (Throwable $e) {
            $latency = (microtime(true) - $startTime) * 1000;

            return HealthCheckResult::unhealthy($shard, $e->getMessage(), (int) $latency);
        }
    }

    /**
     * Check the health of all shards.
     *
     * @return array<string, HealthCheckResult>
     */
    public function checkAll(ShardCollection $shards): array
    {
        $results = [];

        foreach ($shards as $shard) {
            $results[$shard->getId()] = $this->check($shard);
        }

        return $results;
    }

    /**
     * Check if all shards are healthy.
     */
    public function allHealthy(ShardCollection $shards): bool
    {
        foreach ($shards as $shard) {
            if (! $this->check($shard)->isHealthy()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get only healthy shards.
     */
    public function getHealthyShards(ShardCollection $shards): ShardCollection
    {
        return $shards->filter(fn (ShardInterface $shard): bool => $this->check($shard)->isHealthy());
    }

    /**
     * Get only unhealthy shards.
     */
    public function getUnhealthyShards(ShardCollection $shards): ShardCollection
    {
        return $shards->filter(fn (ShardInterface $shard): bool => ! $this->check($shard)->isHealthy());
    }
}

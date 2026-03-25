<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Async;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardCollection;
use Throwable;

use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

/**
 * Executes queries across multiple shards concurrently using AmPHP Fibers.
 *
 * Uses amphp/postgres connection pools to fire shard queries simultaneously
 * on a single thread without process forking.
 */
final class AsyncShardQueryExecutor
{
    /** @var array<string, PostgresConnectionPool> */
    private static array $pools = [];

    /**
     * Execute a raw SQL query on all shards concurrently.
     *
     * Returns results grouped by shard ID.
     *
     * @param  array<int, mixed>  $params
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function queryAll(ShardCollection $shards, string $sql, array $params = [], bool $tolerateDeadShards = false): array
    {
        $sql = self::convertPlaceholders($sql);

        $futures = [];

        foreach ($shards as $shard) {
            $pool = self::getPool($shard);
            $shardId = $shard->getId();

            $futures[$shardId] = async(function () use ($pool, $sql, $params): array {
                $result = $pool->execute($sql, $params);
                $rows = [];
                foreach ($result as $row) {
                    $rows[] = $row;
                }

                return $rows;
            });
        }

        if ($tolerateDeadShards) {
            /** @var array<string, Throwable> $errors */
            /** @var array<string, array<int, array<string, mixed>>> $values */
            [$errors, $values] = awaitAll($futures);

            return $values;
        }

        return await($futures);
    }

    /**
     * Execute a raw SQL query on all shards and return a single scalar per shard.
     *
     * Useful for COUNT, SUM, and other aggregate queries.
     *
     * @param  array<int, mixed>  $params
     * @return array<string, mixed>
     */
    public static function scalarAll(ShardCollection $shards, string $sql, array $params = [], bool $tolerateDeadShards = false): array
    {
        $sql = self::convertPlaceholders($sql);

        $futures = [];

        foreach ($shards as $shard) {
            $pool = self::getPool($shard);
            $shardId = $shard->getId();

            $futures[$shardId] = async(function () use ($pool, $sql, $params): mixed {
                $result = $pool->execute($sql, $params);
                $row = $result->fetchRow();

                return $row !== null ? array_values($row)[0] : null;
            });
        }

        if ($tolerateDeadShards) {
            /** @var array<string, Throwable> $errors */
            /** @var array<string, mixed> $values */
            [$errors, $values] = awaitAll($futures);

            return $values;
        }

        return await($futures);
    }

    /**
     * Execute two different queries concurrently across all shards.
     *
     * Fires 2N queries (N per query type) simultaneously, returning
     * both result sets. Useful for paginate (count + data in one batch).
     *
     * @param  array<int, mixed>  $params1
     * @param  array<int, mixed>  $params2
     * @return array{0: array<string, mixed>, 1: array<string, array<int, array<string, mixed>>>}
     */
    public static function dualQueryAll(
        ShardCollection $shards,
        string $scalarSql,
        array $scalarParams,
        string $dataSql,
        array $dataParams,
        bool $tolerateDeadShards = false,
    ): array {
        $scalarSql = self::convertPlaceholders($scalarSql);
        $dataSql = self::convertPlaceholders($dataSql);

        $scalarFutures = [];
        $dataFutures = [];

        foreach ($shards as $shard) {
            $pool = self::getPool($shard);
            $shardId = $shard->getId();

            $scalarFutures[$shardId] = async(function () use ($pool, $scalarSql, $scalarParams): mixed {
                $result = $pool->execute($scalarSql, $scalarParams);
                $row = $result->fetchRow();

                return $row !== null ? array_values($row)[0] : null;
            });

            $dataFutures[$shardId] = async(function () use ($pool, $dataSql, $dataParams): array {
                $result = $pool->execute($dataSql, $dataParams);
                $rows = [];
                foreach ($result as $row) {
                    $rows[] = $row;
                }

                return $rows;
            });
        }

        // All 2N futures are already running concurrently via async().
        // Awaiting scalar futures first doesn't block data futures — they
        // continue executing on the event loop while we collect results.
        return [await($scalarFutures), await($dataFutures)];
    }

    /**
     * Close all connection pools and reset the pool cache.
     */
    public static function closeAll(): void
    {
        foreach (self::$pools as $pool) {
            $pool->close();
        }

        self::$pools = [];
    }

    /**
     * Get or create a connection pool for a shard.
     */
    private static function getPool(ShardInterface $shard): PostgresConnectionPool
    {
        $shardId = $shard->getId();

        if (! isset(self::$pools[$shardId])) {
            $config = $shard->getConnectionConfig();

            $poolConfig = new PostgresConfig(
                host: (string) ($config['host'] ?? '127.0.0.1'),
                port: (int) ($config['port'] ?? 5432),
                user: isset($config['username']) ? (string) $config['username'] : null,
                password: isset($config['password']) ? (string) $config['password'] : null,
                database: isset($config['database']) ? (string) $config['database'] : null,
            );

            $maxConnections = (int) config('shardwise.connection_pool.max_connections', 10);
            $idleTimeout = (int) config('shardwise.connection_pool.idle_timeout', 60);

            self::$pools[$shardId] = new PostgresConnectionPool(
                $poolConfig,
                maxConnections: max(1, $maxConnections),
                idleTimeout: max(1, $idleTimeout),
            );
        }

        return self::$pools[$shardId];
    }

    /**
     * Convert Eloquent-style `?` placeholder SQL to PostgreSQL `$1, $2, ...` format.
     */
    private static function convertPlaceholders(string $sql): string
    {
        $index = 0;

        return (string) preg_replace_callback('/\?/', function () use (&$index): string {
            return '$'.++$index;
        }, $sql);
    }
}

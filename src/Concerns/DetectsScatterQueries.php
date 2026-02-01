<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Detects and logs scatter queries (queries that span all shards).
 *
 * Scatter queries can significantly impact performance as they require
 * executing the same query on every shard and merging results.
 */
trait DetectsScatterQueries
{
    /**
     * Whether scatter query detection is enabled.
     */
    private static bool $detectScatterQueries = true;

    /**
     * Whether to log scatter queries.
     */
    private static bool $logScatterQueries = true;

    /**
     * Callback to execute when scatter query is detected.
     *
     * @var callable|null
     */
    private static $scatterQueryCallback = null;

    /**
     * Enable scatter query detection.
     */
    public static function enableScatterDetection(): void
    {
        self::$detectScatterQueries = true;
    }

    /**
     * Disable scatter query detection.
     */
    public static function disableScatterDetection(): void
    {
        self::$detectScatterQueries = false;
    }

    /**
     * Enable scatter query logging.
     */
    public static function enableScatterLogging(): void
    {
        self::$logScatterQueries = true;
    }

    /**
     * Disable scatter query logging.
     */
    public static function disableScatterLogging(): void
    {
        self::$logScatterQueries = false;
    }

    /**
     * Set a callback to execute when scatter query is detected.
     *
     * @param  callable(string $sql, array<int, mixed> $bindings, int $shardCount): void  $callback
     */
    public static function onScatterQuery(callable $callback): void
    {
        self::$scatterQueryCallback = $callback;
    }

    /**
     * Log a scatter query warning.
     *
     * @param  array<int, mixed>  $bindings
     */
    protected static function logScatterQuery(string $sql, array $bindings, int $shardCount): void
    {
        if (! self::$detectScatterQueries) {
            return;
        }

        if (self::$logScatterQueries) {
            Log::warning('Scatter query detected: Query will execute on all shards', [
                'sql' => $sql,
                'bindings' => $bindings,
                'shard_count' => $shardCount,
                'recommendation' => 'Consider adding a shard key to the WHERE clause to target a specific shard.',
            ]);
        }

        if (self::$scatterQueryCallback !== null) {
            (self::$scatterQueryCallback)($sql, $bindings, $shardCount);
        }
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Metrics;

use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Collects and analyzes shard metrics for monitoring and hotspot detection.
 */
final class ShardMetrics
{
    /**
     * Query counts per shard.
     *
     * @var array<string, int>
     */
    private array $queryCounts = [];

    /**
     * Total query time per shard in milliseconds.
     *
     * @var array<string, float>
     */
    private array $queryTimes = [];

    /**
     * Cross-shard query count.
     */
    private int $crossShardQueries = 0;

    /**
     * Whether metrics collection is enabled.
     */
    private bool $enabled = true;

    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Flush the singleton instance and all collected metrics.
     *
     * This is critical for long-running processes (Octane, Swoole, RoadRunner)
     * to prevent cross-request data leaks.
     */
    public static function flush(): void
    {
        if (self::$instance !== null) {
            self::$instance->reset();
        }

        self::$instance = null;
    }

    /**
     * Enable metrics collection.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable metrics collection.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if metrics collection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record a query executed on a shard.
     *
     * @param  float  $durationMs  Query duration in milliseconds
     */
    public function recordQuery(ShardInterface|string $shard, float $durationMs): void
    {
        if (! $this->enabled) {
            return;
        }

        $shardId = $shard instanceof ShardInterface ? $shard->getId() : $shard;

        $this->queryCounts[$shardId] = ($this->queryCounts[$shardId] ?? 0) + 1;
        $this->queryTimes[$shardId] = ($this->queryTimes[$shardId] ?? 0.0) + $durationMs;
    }

    /**
     * Record a cross-shard query.
     */
    public function recordCrossShardQuery(int $shardCount, float $durationMs): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->crossShardQueries++;
    }

    /**
     * Get the query count for a shard.
     */
    public function getQueryCount(string $shardId): int
    {
        return $this->queryCounts[$shardId] ?? 0;
    }

    /**
     * Get the total query time for a shard in milliseconds.
     */
    public function getQueryTime(string $shardId): float
    {
        return $this->queryTimes[$shardId] ?? 0.0;
    }

    /**
     * Get the average query time for a shard in milliseconds.
     */
    public function getAverageQueryTime(string $shardId): float
    {
        $count = $this->getQueryCount($shardId);

        return $count > 0 ? $this->getQueryTime($shardId) / $count : 0.0;
    }

    /**
     * Get the cross-shard query count.
     */
    public function getCrossShardQueryCount(): int
    {
        return $this->crossShardQueries;
    }

    /**
     * Get all query counts.
     *
     * @return array<string, int>
     */
    public function getAllQueryCounts(): array
    {
        return $this->queryCounts;
    }

    /**
     * Get all query times.
     *
     * @return array<string, float>
     */
    public function getAllQueryTimes(): array
    {
        return $this->queryTimes;
    }

    /**
     * Get the total query count across all shards.
     */
    public function getTotalQueryCount(): int
    {
        return array_sum($this->queryCounts);
    }

    /**
     * Detect hotspots (shards with significantly higher load).
     *
     * A hotspot is defined as a shard receiving more than `$threshold` times
     * the average load. Default threshold is 2.0 (twice the average).
     *
     * @return array<string, array{query_count: int, ratio: float}>
     */
    public function detectHotspots(float $threshold = 2.0): array
    {
        if ($this->queryCounts === []) {
            return [];
        }

        $total = array_sum($this->queryCounts);
        $shardCount = count($this->queryCounts);
        $average = $total / $shardCount;

        $hotspots = [];

        foreach ($this->queryCounts as $shardId => $count) {
            $ratio = $average > 0 ? $count / $average : 0;

            if ($ratio >= $threshold) {
                $hotspots[$shardId] = [
                    'query_count' => $count,
                    'ratio' => round($ratio, 2),
                ];
            }
        }

        // Sort by ratio descending
        uasort($hotspots, fn (array $a, array $b): int => $b['ratio'] <=> $a['ratio']);

        return $hotspots;
    }

    /**
     * Detect slow shards based on average query time.
     *
     * @param  float  $thresholdMs  Minimum average time to be considered slow
     * @return array<string, array{avg_time_ms: float, query_count: int}>
     */
    public function detectSlowShards(float $thresholdMs = 100.0): array
    {
        $slowShards = [];

        foreach ($this->queryCounts as $shardId => $count) {
            $avgTime = $this->getAverageQueryTime($shardId);

            if ($avgTime >= $thresholdMs) {
                $slowShards[$shardId] = [
                    'avg_time_ms' => round($avgTime, 2),
                    'query_count' => $count,
                ];
            }
        }

        // Sort by average time descending
        uasort($slowShards, fn (array $a, array $b): int => $b['avg_time_ms'] <=> $a['avg_time_ms']);

        return $slowShards;
    }

    /**
     * Get a summary of all metrics.
     *
     * @return array{
     *     total_queries: int,
     *     cross_shard_queries: int,
     *     shards: array<string, array{queries: int, total_time_ms: float, avg_time_ms: float}>,
     *     hotspots: array<string, array{query_count: int, ratio: float}>
     * }
     */
    public function getSummary(float $hotspotThreshold = 2.0): array
    {
        $shards = [];

        foreach ($this->queryCounts as $shardId => $count) {
            $shards[$shardId] = [
                'queries' => $count,
                'total_time_ms' => round($this->getQueryTime($shardId), 2),
                'avg_time_ms' => round($this->getAverageQueryTime($shardId), 2),
            ];
        }

        return [
            'total_queries' => $this->getTotalQueryCount(),
            'cross_shard_queries' => $this->crossShardQueries,
            'shards' => $shards,
            'hotspots' => $this->detectHotspots($hotspotThreshold),
        ];
    }

    /**
     * Reset all metrics.
     */
    public function reset(): void
    {
        $this->queryCounts = [];
        $this->queryTimes = [];
        $this->crossShardQueries = 0;
    }
}

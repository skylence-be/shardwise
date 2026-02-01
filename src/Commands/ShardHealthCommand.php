<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Illuminate\Console\Command;
use Skylence\Shardwise\Health\HealthCheckResult;
use Skylence\Shardwise\Health\ShardHealthChecker;
use Skylence\Shardwise\Metrics\ShardMetrics;
use Skylence\Shardwise\ShardwiseManager;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class ShardHealthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:health
        {--continuous : Run health checks continuously}
        {--interval=30 : Interval in seconds for continuous mode}
        {--metrics : Include query metrics and hotspot detection}
        {--json : Output as JSON}';

    /**
     * @var string
     */
    protected $description = 'Check the health of all shards';

    public function __construct(
        private readonly ShardwiseManager $manager,
        private readonly ShardHealthChecker $healthChecker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $continuous = (bool) $this->option('continuous');
        $interval = (int) $this->option('interval');
        $includeMetrics = (bool) $this->option('metrics');
        $asJson = (bool) $this->option('json');

        if ($continuous) {
            return $this->runContinuous($interval, $asJson, $includeMetrics);
        }

        return $this->runOnce($asJson, $includeMetrics);
    }

    /**
     * Run health check once.
     */
    private function runOnce(bool $asJson, bool $includeMetrics): int
    {
        $results = spin(
            fn () => $this->healthChecker->checkAll($this->manager->getShards()),
            'Checking shard health...'
        );

        if ($asJson) {
            $this->outputJson($results, $includeMetrics);

            return $this->determineExitCode($results);
        }

        $this->displayResults($results);

        if ($includeMetrics) {
            $this->displayMetrics();
        }

        return $this->determineExitCode($results);
    }

    /**
     * Run health checks continuously.
     */
    private function runContinuous(int $interval, bool $asJson, bool $includeMetrics): int
    {
        info("Running health checks every {$interval} seconds. Press Ctrl+C to stop.");

        while (true) {
            $results = $this->healthChecker->checkAll($this->manager->getShards());

            if ($asJson) {
                $this->outputJson($results, $includeMetrics);
            } else {
                $this->line('');
                $this->line('['.date('Y-m-d H:i:s').']');
                $this->displayResults($results);

                if ($includeMetrics) {
                    $this->displayMetrics();
                }
            }

            sleep($interval);
        }
    }

    /**
     * Display health check results.
     *
     * @param  array<string, HealthCheckResult>  $results
     */
    private function displayResults(array $results): void
    {
        $rows = [];
        $hasUnhealthy = false;

        foreach ($results as $shardId => $result) {
            $status = $result->isHealthy() ? '<fg=green>✓ Healthy</>' : '<fg=red>✗ Unhealthy</>';
            $latency = $result->getLatencyMs() !== null ? $result->getLatencyMs().'ms' : 'N/A';
            $error = $result->getError() ?? '-';

            if (! $result->isHealthy()) {
                $hasUnhealthy = true;
            }

            $rows[] = [
                $shardId,
                $status,
                $latency,
                mb_strlen($error) > 50 ? mb_substr($error, 0, 50).'...' : $error,
            ];
        }

        table(
            ['Shard', 'Status', 'Latency', 'Error'],
            $rows
        );

        if ($hasUnhealthy) {
            error('Some shards are unhealthy.');
        } else {
            info('All shards are healthy.');
        }
    }

    /**
     * Display query metrics.
     */
    private function displayMetrics(): void
    {
        $metrics = ShardMetrics::getInstance();
        $summary = $metrics->getSummary();

        $this->line('');
        info('Query Metrics');

        if ($summary['total_queries'] === 0) {
            $this->line('No queries recorded yet.');

            return;
        }

        $this->line("Total queries: {$summary['total_queries']}");
        $this->line("Cross-shard queries: {$summary['cross_shard_queries']}");

        if ($summary['shards'] !== []) {
            $this->line('');
            $rows = [];

            foreach ($summary['shards'] as $shardId => $data) {
                $rows[] = [
                    $shardId,
                    (string) $data['queries'],
                    $data['total_time_ms'].'ms',
                    $data['avg_time_ms'].'ms',
                ];
            }

            table(
                ['Shard', 'Queries', 'Total Time', 'Avg Time'],
                $rows
            );
        }

        if ($summary['hotspots'] !== []) {
            $this->line('');
            warning('Hotspots Detected');

            foreach ($summary['hotspots'] as $shardId => $data) {
                $this->line("  {$shardId}: {$data['query_count']} queries ({$data['ratio']}x average)");
            }
        }
    }

    /**
     * Output results as JSON.
     *
     * @param  array<string, HealthCheckResult>  $results
     */
    private function outputJson(array $results, bool $includeMetrics = false): void
    {
        $data = ['health' => []];

        foreach ($results as $shardId => $result) {
            $data['health'][$shardId] = [
                'healthy' => $result->isHealthy(),
                'latency_ms' => $result->getLatencyMs(),
                'error' => $result->getError(),
                'checked_at' => $result->getCheckedAt()->format('c'),
            ];
        }

        if ($includeMetrics) {
            $data['metrics'] = ShardMetrics::getInstance()->getSummary();
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Determine exit code based on results.
     *
     * @param  array<string, HealthCheckResult>  $results
     */
    private function determineExitCode(array $results): int
    {
        foreach ($results as $result) {
            if (! $result->isHealthy()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

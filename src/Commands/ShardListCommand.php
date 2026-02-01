<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Illuminate\Console\Command;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Health\ShardHealthChecker;
use Skylence\Shardwise\ShardwiseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class ShardListCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:list
        {--health : Include health check status}
        {--json : Output as JSON}';

    /**
     * @var string
     */
    protected $description = 'List all configured shards';

    public function __construct(
        private readonly ShardwiseManager $manager,
        private readonly ShardHealthChecker $healthChecker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shards = $this->manager->getShards();

        if ($shards->isEmpty()) {
            warning('No shards configured.');

            return self::SUCCESS;
        }

        $includeHealth = (bool) $this->option('health');
        $asJson = (bool) $this->option('json');

        $data = $this->collectShardData($shards->all(), $includeHealth);

        if ($asJson) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayTable($data, $includeHealth);

        return self::SUCCESS;
    }

    /**
     * Collect shard data for display.
     *
     * @param  array<string, ShardInterface>  $shards
     * @return array<int, array<string, mixed>>
     */
    private function collectShardData(array $shards, bool $includeHealth): array
    {
        $data = [];

        foreach ($shards as $shard) {
            $row = [
                'id' => $shard->getId(),
                'name' => $shard->getName(),
                'connection' => $shard->getConnectionName(),
                'weight' => $shard->getWeight(),
                'active' => $shard->isActive() ? 'Yes' : 'No',
                'read_only' => $shard->isReadOnly() ? 'Yes' : 'No',
            ];

            if ($includeHealth) {
                $result = $this->healthChecker->check($shard);
                $row['status'] = $result->isHealthy() ? '✓ Healthy' : '✗ Unhealthy';
                $row['latency'] = $result->getLatencyMs() !== null
                    ? $result->getLatencyMs().'ms'
                    : 'N/A';
            }

            $data[] = $row;
        }

        return $data;
    }

    /**
     * Display the shard table.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    private function displayTable(array $data, bool $includeHealth): void
    {
        info('Configured Shards');

        $headers = ['ID', 'Name', 'Connection', 'Weight', 'Active', 'Read Only'];

        if ($includeHealth) {
            $headers[] = 'Status';
            $headers[] = 'Latency';
        }

        $rows = array_map(function (array $row) use ($includeHealth): array {
            /** @var string $id */
            $id = $row['id'];
            /** @var string $name */
            $name = $row['name'];
            /** @var string $connection */
            $connection = $row['connection'];
            /** @var int $weight */
            $weight = $row['weight'];
            /** @var string $active */
            $active = $row['active'];
            /** @var string $readOnly */
            $readOnly = $row['read_only'];

            $values = [
                $id,
                $name,
                $connection,
                (string) $weight,
                $active,
                $readOnly,
            ];

            if ($includeHealth) {
                /** @var string $status */
                $status = $row['status'];
                /** @var string $latency */
                $latency = $row['latency'];
                $values[] = $status;
                $values[] = $latency;
            }

            return $values;
        }, $data);

        table($headers, $rows);
    }
}

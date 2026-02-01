<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Exception;
use Illuminate\Console\Command;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Migrations\ShardMigrationRepository;
use Skylence\Shardwise\ShardwiseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class ShardStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:status
        {--shard= : Show status for a specific shard}
        {--pending : Only show pending migrations}';

    /**
     * @var string
     */
    protected $description = 'Show the migration status for shards';

    public function __construct(
        private readonly ShardwiseManager $manager,
        private readonly ShardMigrationRepository $repository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shards = $this->getTargetShards();

        if ($shards === []) {
            warning('No shards configured.');

            return self::SUCCESS;
        }

        foreach ($shards as $shard) {
            $this->showStatusForShard($shard);
        }

        return self::SUCCESS;
    }

    /**
     * Get the target shards.
     *
     * @return array<ShardInterface>
     */
    private function getTargetShards(): array
    {
        $shardIdOption = $this->option('shard');

        if (is_string($shardIdOption)) {
            try {
                return [$this->manager->getShard($shardIdOption)];
            } catch (Exception) {
                warning("Shard '{$shardIdOption}' not found.");

                return [];
            }
        }

        return $this->manager->getShards()->all();
    }

    /**
     * Show migration status for a shard.
     */
    private function showStatusForShard(ShardInterface $shard): void
    {
        info("Migration status for {$shard->getName()} ({$shard->getId()})");

        $status = spin(
            fn () => $this->repository->getMigrationStatus($shard),
            'Checking migration status...'
        );

        $onlyPending = $this->option('pending');

        $rows = [];
        foreach ($status as $migration => $ran) {
            if ($onlyPending && $ran) {
                continue;
            }

            $rows[] = [
                $migration,
                $ran ? '<fg=green>Ran</>' : '<fg=yellow>Pending</>',
            ];
        }

        if ($rows === []) {
            if ($onlyPending) {
                info('No pending migrations.');
            } else {
                info('No migrations found.');
            }
        } else {
            table(['Migration', 'Status'], $rows);
        }

        $this->line('');
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Exception;
use Illuminate\Console\Command;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Migrations\ShardMigrator;
use Skylence\Shardwise\ShardwiseManager;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

final class ShardMigrateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:migrate
        {--shard= : Run migrations on a specific shard}
        {--table-group= : Run migrations for a specific table group}
        {--seed : Run seeders after migration}
        {--fresh : Drop all tables and re-run migrations}
        {--rollback : Rollback the last migration}
        {--step=1 : Number of migrations to rollback}
        {--force : Force running in production}';

    /**
     * @var string
     */
    protected $description = 'Run database migrations on shards';

    public function __construct(
        private readonly ShardwiseManager $manager,
        private readonly ShardMigrator $migrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shards = $this->getTargetShards();

        if ($shards === []) {
            warning('No shards to migrate.');

            return self::SUCCESS;
        }

        $isRollback = (bool) $this->option('rollback');
        $isFresh = (bool) $this->option('fresh');
        $shouldSeed = (bool) $this->option('seed');
        $step = (int) $this->option('step');

        foreach ($shards as $shard) {
            $this->migrateShard($shard, $isRollback, $isFresh, $shouldSeed, $step);
        }

        info('Migration complete.');

        return self::SUCCESS;
    }

    /**
     * Get the target shards for migration.
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
                error("Shard '{$shardIdOption}' not found.");

                return [];
            }
        }

        return $this->manager->getShards()->active()->all();
    }

    /**
     * Run migration on a single shard.
     */
    private function migrateShard(
        ShardInterface $shard,
        bool $isRollback,
        bool $isFresh,
        bool $shouldSeed,
        int $step,
    ): void {
        $shardName = $shard->getName();

        if ($isFresh) {
            spin(
                fn () => $this->migrator->fresh($shard, $this->option('force') === true),
                "Running fresh migration on {$shardName}..."
            );
        } elseif ($isRollback) {
            spin(
                fn () => $this->migrator->rollback($shard, $step),
                "Rolling back {$step} migration(s) on {$shardName}..."
            );
        } else {
            spin(
                fn () => $this->migrator->migrate($shard, $this->option('force') === true),
                "Migrating {$shardName}..."
            );
        }

        if ($shouldSeed && ! $isRollback) {
            spin(
                fn () => $this->migrator->seed($shard),
                "Seeding {$shardName}..."
            );
        }
    }
}

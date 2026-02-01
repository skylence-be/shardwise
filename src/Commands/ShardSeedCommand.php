<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Exception;
use Illuminate\Console\Command;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Migrations\ShardMigrator;
use Skylence\Shardwise\ShardwiseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

final class ShardSeedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:seed
        {--shard= : Seed a specific shard}
        {--class= : The class name of the seeder to run}
        {--force : Force running in production}';

    /**
     * @var string
     */
    protected $description = 'Run database seeders on shards';

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
            warning('No shards to seed.');

            return self::SUCCESS;
        }

        $seederClassOption = $this->option('class');
        $seederClass = is_string($seederClassOption) ? $seederClassOption : null;

        foreach ($shards as $shard) {
            $this->seedShard($shard, $seederClass);
        }

        info('Seeding complete.');

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

        return $this->manager->getShards()->active()->all();
    }

    /**
     * Seed a single shard.
     */
    private function seedShard(ShardInterface $shard, ?string $seederClass): void
    {
        $shardName = $shard->getName();

        spin(
            fn () => $this->migrator->seed($shard, $seederClass),
            "Seeding {$shardName}..."
        );
    }
}

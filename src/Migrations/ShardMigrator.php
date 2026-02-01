<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Migrations;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardwiseManager;

/**
 * Handles running migrations on shards.
 */
final class ShardMigrator
{
    public function __construct(
        private readonly ShardwiseManager $manager,
        private readonly ConsoleKernel $console,
    ) {}

    /**
     * Run migrations on a shard.
     */
    public function migrate(ShardInterface $shard, bool $force = false): void
    {
        $this->manager->run($shard, function () use ($force): void {
            $path = $this->getMigrationPath();

            Artisan::call('migrate', [
                '--path' => $path,
                '--realpath' => true,
                '--force' => $force,
            ]);
        });
    }

    /**
     * Rollback migrations on a shard.
     */
    public function rollback(ShardInterface $shard, int $step = 1): void
    {
        $this->manager->run($shard, function () use ($step): void {
            $path = $this->getMigrationPath();

            Artisan::call('migrate:rollback', [
                '--path' => $path,
                '--realpath' => true,
                '--step' => $step,
            ]);
        });
    }

    /**
     * Run fresh migrations on a shard (drop all tables and re-run).
     */
    public function fresh(ShardInterface $shard, bool $force = false): void
    {
        $this->manager->run($shard, function () use ($force): void {
            $path = $this->getMigrationPath();

            Artisan::call('migrate:fresh', [
                '--path' => $path,
                '--realpath' => true,
                '--force' => $force,
            ]);
        });
    }

    /**
     * Reset migrations on a shard (rollback all).
     */
    public function reset(ShardInterface $shard): void
    {
        $this->manager->run($shard, function (): void {
            $path = $this->getMigrationPath();

            Artisan::call('migrate:reset', [
                '--path' => $path,
                '--realpath' => true,
            ]);
        });
    }

    /**
     * Run seeders on a shard.
     */
    public function seed(ShardInterface $shard, ?string $class = null): void
    {
        $this->manager->run($shard, function () use ($class): void {
            $options = [];

            if ($class !== null) {
                $options['--class'] = $class;
            }

            Artisan::call('db:seed', $options);
        });
    }

    /**
     * Get the migration path.
     */
    private function getMigrationPath(): string
    {
        /** @var string */
        return config('shardwise.migrations.path', database_path('migrations/shards'));
    }
}

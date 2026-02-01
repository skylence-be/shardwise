<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Migrations;

use Exception;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\Filesystem;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardwiseManager;

/**
 * Repository for tracking per-shard migrations.
 */
final class ShardMigrationRepository
{
    public function __construct(
        private readonly ShardwiseManager $manager,
        private readonly ConnectionResolverInterface $resolver,
        private readonly Filesystem $files,
    ) {}

    /**
     * Get the migration status for a shard.
     *
     * @return array<string, bool>
     */
    public function getMigrationStatus(ShardInterface $shard): array
    {
        $ran = $this->getRanMigrations($shard);
        $all = $this->getAllMigrations();

        $status = [];

        foreach ($all as $migration) {
            $status[$migration] = in_array($migration, $ran, true);
        }

        return $status;
    }

    /**
     * Get all ran migrations for a shard.
     *
     * @return array<string>
     */
    public function getRanMigrations(ShardInterface $shard): array
    {
        return $this->manager->run($shard, function () {
            $table = $this->getMigrationTable();

            try {
                $connection = $this->resolver->connection();

                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    return [];
                }

                $migrations = $connection->table($table)
                    ->orderBy('batch')
                    ->orderBy('migration')
                    ->pluck('migration')
                    ->all();

                return array_map(fn ($m): string => (string) $m, $migrations);
            } catch (Exception) {
                return [];
            }
        });
    }

    /**
     * Get all migration files.
     *
     * @return array<string>
     */
    public function getAllMigrations(): array
    {
        $path = $this->getMigrationPath();

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        $files = $this->files->glob("{$path}/*.php");

        if ($files === false) {
            return [];
        }

        $migrations = [];

        foreach ($files as $file) {
            $migrations[] = pathinfo($file, PATHINFO_FILENAME);
        }

        sort($migrations);

        return $migrations;
    }

    /**
     * Get pending migrations for a shard.
     *
     * @return array<string>
     */
    public function getPendingMigrations(ShardInterface $shard): array
    {
        $ran = $this->getRanMigrations($shard);
        $all = $this->getAllMigrations();

        return array_values(array_diff($all, $ran));
    }

    /**
     * Check if there are pending migrations for a shard.
     */
    public function hasPendingMigrations(ShardInterface $shard): bool
    {
        return $this->getPendingMigrations($shard) !== [];
    }

    /**
     * Get the last migration batch number.
     */
    public function getLastBatchNumber(ShardInterface $shard): int
    {
        return $this->manager->run($shard, function (): int {
            $table = $this->getMigrationTable();

            try {
                $connection = $this->resolver->connection();

                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    return 0;
                }

                return (int) $connection->table($table)->max('batch');
            } catch (Exception) {
                return 0;
            }
        });
    }

    /**
     * Get the migration table name.
     */
    private function getMigrationTable(): string
    {
        /** @var string */
        return config('shardwise.migrations.table', 'shardwise_migrations');
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

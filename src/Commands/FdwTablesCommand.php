<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FdwTablesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:fdw-tables
        {table : The table name to create foreign tables for}
        {--execute : Execute the SQL instead of just printing it}';

    /**
     * @var string
     */
    protected $description = 'Generate or execute SQL to create foreign tables and a unified view for a sharded table';

    public function handle(): int
    {
        /** @var string $table */
        $table = $this->argument('table');
        $shards = shardwise()->getShards()->active();

        if ($shards->isEmpty()) {
            $this->warn('No active shards configured.');

            return self::FAILURE;
        }

        /** @var string $centralConnection */
        $centralConnection = config('shardwise.fdw.coordinator_connection', config('shardwise.central_connection', 'pgsql'));

        /** @var string $viewPrefix */
        $viewPrefix = config('shardwise.fdw.view_prefix', 'all_');

        $sql = [];
        $sql[] = "-- Shardwise postgres_fdw foreign tables for: {$table}";
        $sql[] = '';

        $foreignTableNames = [];

        foreach ($shards as $shard) {
            $serverId = str_replace('-', '_', $shard->getId());
            $foreignTable = "{$serverId}_{$table}";
            $foreignTableNames[] = [
                'name' => $foreignTable,
                'shard_id' => $shard->getId(),
            ];

            $sql[] = "-- Foreign table for shard: {$shard->getName()}";
            $sql[] = "CREATE FOREIGN TABLE IF NOT EXISTS {$foreignTable} (";
            $sql[] = "    LIKE {$table}";
            $sql[] = ") SERVER {$serverId} OPTIONS (table_name '{$table}');";
            $sql[] = '';
        }

        // Create unified view
        $viewName = "{$viewPrefix}{$table}";
        $sql[] = '-- Unified view combining all shards';
        $sql[] = "CREATE OR REPLACE VIEW {$viewName} AS";

        $parts = [];
        foreach ($foreignTableNames as $entry) {
            $parts[] = "    SELECT *, '{$entry['shard_id']}' AS _shard_id FROM {$entry['name']}";
        }
        $sql[] = implode("\n    UNION ALL\n", $parts).';';

        $output = implode("\n", $sql);

        if ($this->option('execute')) {
            DB::connection($centralConnection)->unprepared($output);
            $this->info("Foreign tables and view '{$viewName}' created successfully.");
        } else {
            $this->line($output);
        }

        return self::SUCCESS;
    }
}

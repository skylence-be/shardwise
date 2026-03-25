<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FdwSetupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:fdw-setup
        {--execute : Execute the SQL instead of just printing it}';

    /**
     * @var string
     */
    protected $description = 'Generate or execute SQL to set up postgres_fdw for cross-shard queries';

    public function handle(): int
    {
        $shards = shardwise()->getShards()->active();

        if ($shards->isEmpty()) {
            $this->warn('No active shards configured.');

            return self::FAILURE;
        }

        /** @var string $centralConnection */
        $centralConnection = config('shardwise.fdw.coordinator_connection', config('shardwise.central_connection', 'pgsql'));

        $sql = [];
        $sql[] = '-- Shardwise postgres_fdw setup';
        $sql[] = '-- Run this on the coordinator database';
        $sql[] = '';
        $sql[] = 'CREATE EXTENSION IF NOT EXISTS postgres_fdw;';
        $sql[] = '';

        foreach ($shards as $shard) {
            $config = $shard->getConnectionConfig();
            $serverId = str_replace('-', '_', $shard->getId());

            /** @var string $host */
            $host = $config['host'] ?? '127.0.0.1';
            /** @var string $port */
            $port = (string) ($config['port'] ?? '5432');
            /** @var string $database */
            $database = $config['database'] ?? '';
            /** @var string $username */
            $username = $config['username'] ?? '';
            /** @var string $password */
            $password = $config['password'] ?? '';

            $sql[] = "-- Server: {$shard->getName()}";
            $sql[] = "CREATE SERVER IF NOT EXISTS {$serverId} FOREIGN DATA WRAPPER postgres_fdw";
            $sql[] = "    OPTIONS (host '{$host}', port '{$port}', dbname '{$database}');";
            $sql[] = '';

            $sql[] = "CREATE USER MAPPING IF NOT EXISTS FOR CURRENT_USER SERVER {$serverId}";
            $sql[] = "    OPTIONS (user '{$username}', password '{$password}');";
            $sql[] = '';
        }

        $sql[] = '-- Foreign tables and views will need to be created per-table';
        $sql[] = '-- Use: php artisan shardwise:fdw-tables {table_name}';

        $output = implode("\n", $sql);

        if ($this->option('execute')) {
            DB::connection($centralConnection)->unprepared($output);
            $this->info('FDW setup executed successfully.');
        } else {
            $this->line($output);
        }

        return self::SUCCESS;
    }
}

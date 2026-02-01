<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Helper class to execute queries on the central (non-sharded) database.
 */
final class Central
{
    /**
     * Execute a callback using the central database connection.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        $previousConnection = DB::getDefaultConnection();

        try {
            /** @var string $centralConnection */
            $centralConnection = config('shardwise.central_connection', 'mysql');
            DB::setDefaultConnection($centralConnection);

            return $callback();
        } finally {
            DB::setDefaultConnection($previousConnection);
        }
    }

    /**
     * Get the central database connection name.
     */
    public static function connectionName(): string
    {
        /** @var string */
        return config('shardwise.central_connection', 'mysql');
    }

    /**
     * Get the central database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    public static function connection(): mixed
    {
        return DB::connection(self::connectionName());
    }
}

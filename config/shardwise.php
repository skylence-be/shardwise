<?php

declare(strict_types=1);

use Skylence\Shardwise\Bootstrappers\CacheBootstrapper;
use Skylence\Shardwise\Bootstrappers\DatabaseBootstrapper;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Routing Strategy
    |--------------------------------------------------------------------------
    |
    | The default strategy used to route queries to shards. Available options:
    | 'consistent_hash', 'range', 'modulo', or a custom strategy class.
    |
    */
    'default_strategy' => env('SHARDWISE_STRATEGY', 'consistent_hash'),

    /*
    |--------------------------------------------------------------------------
    | Central Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for non-sharded (central) data.
    | This connection is used for global lookups and cross-shard operations.
    |
    */
    'central_connection' => env('SHARDWISE_CENTRAL_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Shards Configuration
    |--------------------------------------------------------------------------
    |
    | Define your database shards here. Each shard requires a unique ID
    | and database connection configuration.
    |
    | Example:
    | 'shards' => [
    |     'shard-1' => [
    |         'name' => 'Shard 1',
    |         'connection' => 'shardwise_shard_1',
    |         'weight' => 1,
    |         'active' => true,
    |         'read_only' => false,
    |         'database' => [
    |             'driver' => 'mysql',
    |             'host' => env('SHARD_1_HOST', '127.0.0.1'),
    |             'port' => env('SHARD_1_PORT', '3306'),
    |             'database' => env('SHARD_1_DATABASE', 'shard_1'),
    |             'username' => env('SHARD_1_USERNAME', 'root'),
    |             'password' => env('SHARD_1_PASSWORD', ''),
    |         ],
    |         'metadata' => [
    |             'region' => 'us-east',
    |         ],
    |     ],
    | ],
    |
    */
    'shards' => [
        // Define your shards here
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Groups
    |--------------------------------------------------------------------------
    |
    | Define groups of related tables that should always be routed together.
    | This ensures referential integrity for related data.
    |
    | Example:
    | 'table_groups' => [
    |     'users' => ['users', 'user_profiles', 'user_settings', 'user_tokens'],
    |     'orders' => ['orders', 'order_items', 'order_payments'],
    | ],
    |
    */
    'table_groups' => [
        // Define your table groups here
    ],

    /*
    |--------------------------------------------------------------------------
    | Consistent Hash Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the consistent hash routing strategy.
    |
    */
    'consistent_hash' => [
        /*
        | Number of virtual nodes per shard. Higher values provide better
        | key distribution but use more memory. Recommended: 100-200.
        */
        'virtual_nodes' => (int) env('SHARDWISE_VIRTUAL_NODES', 150),

        /*
        | Hash algorithm to use. Options: 'xxh128', 'xxh64', 'sha256', 'md5'.
        | xxh128 is recommended for best performance.
        */
        'hash_algorithm' => env('SHARDWISE_HASH_ALGORITHM', 'xxh128'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UUID Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for shard-aware UUID generation.
    |
    */
    'uuid' => [
        /*
        | UUID version to generate. Version 7 is recommended for sortability.
        */
        'version' => (int) env('SHARDWISE_UUID_VERSION', 7),

        /*
        | Whether to embed shard metadata in generated UUIDs.
        | When enabled, the shard ID can be extracted from the UUID.
        */
        'embed_shard_metadata' => (bool) env('SHARDWISE_UUID_EMBED_METADATA', true),

        /*
        | Number of bits to use for the shard ID in the UUID.
        | 10 bits supports up to 1024 shards.
        */
        'shard_bits' => (int) env('SHARDWISE_UUID_SHARD_BITS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrappers
    |--------------------------------------------------------------------------
    |
    | Bootstrappers are executed when entering/exiting a shard context.
    | They handle setup and teardown of shard-specific resources.
    |
    */
    'bootstrappers' => [
        DatabaseBootstrapper::class,
        CacheBootstrapper::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Integration
    |--------------------------------------------------------------------------
    |
    | Settings for queue job shard context preservation.
    |
    */
    'queue' => [
        /*
        | Whether queue jobs should automatically preserve shard context.
        */
        'aware' => (bool) env('SHARDWISE_QUEUE_AWARE', true),

        /*
        | The key used in job payloads to store the shard ID.
        */
        'payload_key' => 'shardwise_shard_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Pool Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for database connection pooling.
    |
    */
    'connection_pool' => [
        /*
        | Maximum number of connections per shard.
        */
        'max_connections' => (int) env('SHARDWISE_MAX_CONNECTIONS', 10),

        /*
        | Idle connection timeout in seconds.
        */
        'idle_timeout' => (int) env('SHARDWISE_IDLE_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Shard Tolerance
    |--------------------------------------------------------------------------
    |
    | When enabled, cross-shard queries will continue executing on remaining
    | shards even if one or more shards fail. Failures are silently skipped.
    | This is useful in production when partial results are acceptable.
    |
    */
    'dead_shard_tolerance' => (bool) env('SHARDWISE_DEAD_SHARD_TOLERANCE', false),

    /*
    |--------------------------------------------------------------------------
    | Health Check Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for shard health monitoring.
    |
    */
    'health' => [
        /*
        | Timeout for health check queries in seconds.
        */
        'timeout' => (int) env('SHARDWISE_HEALTH_TIMEOUT', 5),

        /*
        | Query to execute for health checks.
        */
        'query' => 'SELECT 1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Co-location Routing
    |--------------------------------------------------------------------------
    |
    | When enabled, queries with a WHERE clause on the shard key column
    | are automatically routed to a single shard instead of querying all.
    |
    */
    'co_location' => [
        'enabled' => (bool) env('SHARDWISE_COLOCATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Parallel Query Execution
    |--------------------------------------------------------------------------
    |
    | When enabled, cross-shard queries run in parallel using Laravel's
    | Concurrency facade instead of sequentially.
    |
    */
    'parallel_queries' => [
        'enabled' => (bool) env('SHARDWISE_PARALLEL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Foreign Data Wrapper
    |--------------------------------------------------------------------------
    |
    | Configuration for postgres_fdw support. When using the fdw commands,
    | the coordinator connection is used to create foreign servers, user
    | mappings, foreign tables, and unified views.
    |
    */
    'fdw' => [
        'enabled' => (bool) env('SHARDWISE_FDW_ENABLED', false),
        'coordinator_connection' => env('SHARDWISE_FDW_CONNECTION', 'pgsql'),
        'view_prefix' => 'all_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Settings for shard migrations.
    |
    */
    'migrations' => [
        /*
        | Path to shard-specific migrations.
        */
        'path' => database_path('migrations/shards'),

        /*
        | Migration table name for tracking shard migrations.
        */
        'table' => 'shardwise_migrations',
    ],
];

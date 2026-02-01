<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Skylence\Shardwise\ShardContext;
use Skylence\Shardwise\ShardwiseServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any shard context from previous tests
        ShardContext::clear();
    }

    protected function tearDown(): void
    {
        // Clear shard context after each test
        ShardContext::clear();

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ShardwiseServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Shardwise' => \Skylence\Shardwise\Facades\Shardwise::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Set up test shards configuration
        $app['config']->set('shardwise.shards', [
            'shard-1' => [
                'name' => 'Test Shard 1',
                'connection' => 'shardwise_shard_1',
                'weight' => 1,
                'active' => true,
                'read_only' => false,
                'database' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
            'shard-2' => [
                'name' => 'Test Shard 2',
                'connection' => 'shardwise_shard_2',
                'weight' => 1,
                'active' => true,
                'read_only' => false,
                'database' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
            'shard-3' => [
                'name' => 'Test Shard 3',
                'connection' => 'shardwise_shard_3',
                'weight' => 1,
                'active' => true,
                'read_only' => false,
                'database' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ]);

        $app['config']->set('shardwise.table_groups', [
            'users' => ['users', 'user_profiles', 'user_settings'],
        ]);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}

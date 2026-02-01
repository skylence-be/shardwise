<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Facades;

use Illuminate\Support\Facades\Facade;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardRouterInterface;
use Skylence\Shardwise\ShardCollection;
use Skylence\Shardwise\ShardwiseManager;
use Skylence\Shardwise\Testing\ShardwiseFake;

/**
 * @method static void initialize(ShardInterface|string $shard)
 * @method static void end()
 * @method static mixed run(ShardInterface|string $shard, callable $callback)
 * @method static array runOnAllShards(callable $callback)
 * @method static ShardInterface|null current()
 * @method static bool active()
 * @method static ShardInterface getShard(string $shardId)
 * @method static ShardCollection getShards()
 * @method static ShardRouterInterface getRouter()
 * @method static ShardInterface route(string|int $key)
 * @method static ShardInterface routeForTable(string $table, string|int $key)
 * @method static ShardwiseManager getManager()
 *
 * @see \Skylence\Shardwise\Shardwise
 */
final class Shardwise extends Facade
{
    /**
     * Replace the bound instance with a fake for testing.
     */
    public static function fake(): ShardwiseFake
    {
        $fake = new ShardwiseFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Skylence\Shardwise\Shardwise::class;
    }
}

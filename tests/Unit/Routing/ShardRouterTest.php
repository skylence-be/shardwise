<?php

declare(strict_types=1);

use Skylence\Shardwise\Routing\ShardRouter;
use Skylence\Shardwise\Routing\Strategies\ConsistentHashStrategy;
use Skylence\Shardwise\Routing\TableGroupResolver;
use Skylence\Shardwise\Shard;
use Skylence\Shardwise\ShardCollection;

beforeEach(function (): void {
    $this->shards = ShardCollection::fromConfig([
        'shard-1' => ['name' => 'Shard 1', 'active' => true],
        'shard-2' => ['name' => 'Shard 2', 'active' => true],
        'shard-3' => ['name' => 'Shard 3', 'active' => true],
    ]);

    $this->strategy = new ConsistentHashStrategy(virtualNodes: 100);

    $this->tableGroupResolver = new TableGroupResolver([
        'users' => ['users', 'user_profiles', 'user_settings'],
    ]);

    $this->router = new ShardRouter(
        $this->shards,
        $this->strategy,
        $this->tableGroupResolver
    );
});

it('routes a key to a shard', function (): void {
    $shard = $this->router->route('user:123');

    expect($shard)->toBeInstanceOf(Shard::class)
        ->and($this->shards->has($shard->getId()))->toBeTrue();
});

it('routes the same key consistently', function (): void {
    $shard1 = $this->router->route('user:456');
    $shard2 = $this->router->route('user:456');

    expect($shard1->getId())->toBe($shard2->getId());
});

it('routes table and key together', function (): void {
    $shard = $this->router->routeForTable('orders', 'order:789');

    expect($shard)->toBeInstanceOf(Shard::class);
});

it('routes related tables to the same shard', function (): void {
    // Tables in the same group should route to the same shard for the same key
    $shardUsers = $this->router->routeForTable('users', 'key:123');
    $shardProfiles = $this->router->routeForTable('user_profiles', 'key:123');
    $shardSettings = $this->router->routeForTable('user_settings', 'key:123');

    expect($shardUsers->getId())
        ->toBe($shardProfiles->getId())
        ->toBe($shardSettings->getId());
});

it('routes unrelated tables independently', function (): void {
    // Orders is not in any group, so it routes based only on the key
    $shardUsers = $this->router->routeForTable('users', 'key:123');
    $shardOrders = $this->router->routeForTable('orders', 'key:123');

    // They may or may not be the same (depends on hash), but the routing logic differs
    expect($shardOrders)->toBeInstanceOf(Shard::class);
});

it('can get a shard by id', function (): void {
    $shard = $this->router->getShardById('shard-2');

    expect($shard)->not->toBeNull()
        ->and($shard->getId())->toBe('shard-2');
});

it('returns null for non-existent shard id', function (): void {
    expect($this->router->getShardById('non-existent'))->toBeNull();
});

it('can get all shards', function (): void {
    $shards = $this->router->getShards();

    expect($shards)->toBeInstanceOf(ShardCollection::class)
        ->and($shards->count())->toBe(3);
});

it('can get and set the strategy', function (): void {
    expect($this->router->getStrategy())->toBe($this->strategy);

    $newStrategy = new ConsistentHashStrategy(virtualNodes: 200);
    $this->router->setStrategy($newStrategy);

    expect($this->router->getStrategy())->toBe($newStrategy);
});

it('can get the table group resolver', function (): void {
    expect($this->router->getTableGroupResolver())->toBe($this->tableGroupResolver);
});

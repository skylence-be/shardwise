<?php

declare(strict_types=1);

use Skylence\Shardwise\Shard;

it('can create a shard with constructor', function (): void {
    $shard = new Shard(
        id: 'shard-1',
        name: 'Test Shard',
        connectionName: 'shardwise_shard_1',
        connectionConfig: ['driver' => 'mysql'],
        weight: 2,
        active: true,
        readOnly: false,
        metadata: ['region' => 'us-east'],
    );

    expect($shard->getId())->toBe('shard-1')
        ->and($shard->getName())->toBe('Test Shard')
        ->and($shard->getConnectionName())->toBe('shardwise_shard_1')
        ->and($shard->getConnectionConfig())->toBe(['driver' => 'mysql'])
        ->and($shard->getWeight())->toBe(2)
        ->and($shard->isActive())->toBeTrue()
        ->and($shard->isReadOnly())->toBeFalse()
        ->and($shard->getMetadata())->toBe(['region' => 'us-east']);
});

it('can create a shard from config', function (): void {
    $config = [
        'name' => 'Shard One',
        'connection' => 'custom_connection',
        'weight' => 3,
        'active' => false,
        'read_only' => true,
        'database' => ['driver' => 'pgsql', 'host' => 'localhost'],
        'metadata' => ['dc' => 'dc1'],
    ];

    $shard = Shard::fromConfig('shard-1', $config);

    expect($shard->getId())->toBe('shard-1')
        ->and($shard->getName())->toBe('Shard One')
        ->and($shard->getConnectionName())->toBe('custom_connection')
        ->and($shard->getConnectionConfig())->toBe(['driver' => 'pgsql', 'host' => 'localhost'])
        ->and($shard->getWeight())->toBe(3)
        ->and($shard->isActive())->toBeFalse()
        ->and($shard->isReadOnly())->toBeTrue()
        ->and($shard->getMetadata())->toBe(['dc' => 'dc1']);
});

it('uses defaults when creating from minimal config', function (): void {
    $shard = Shard::fromConfig('shard-1', []);

    expect($shard->getId())->toBe('shard-1')
        ->and($shard->getName())->toBe('shard-1')
        ->and($shard->getConnectionName())->toBe('shardwise_shard-1')
        ->and($shard->getConnectionConfig())->toBe([])
        ->and($shard->getWeight())->toBe(1)
        ->and($shard->isActive())->toBeTrue()
        ->and($shard->isReadOnly())->toBeFalse()
        ->and($shard->getMetadata())->toBe([]);
});

it('can create a new shard with different active state', function (): void {
    $shard = Shard::fromConfig('shard-1', ['active' => true]);
    $inactiveShard = $shard->withActive(false);

    expect($shard->isActive())->toBeTrue()
        ->and($inactiveShard->isActive())->toBeFalse()
        ->and($inactiveShard->getId())->toBe($shard->getId());
});

it('can create a new shard with different read-only state', function (): void {
    $shard = Shard::fromConfig('shard-1', ['read_only' => false]);
    $readOnlyShard = $shard->withReadOnly(true);

    expect($shard->isReadOnly())->toBeFalse()
        ->and($readOnlyShard->isReadOnly())->toBeTrue()
        ->and($readOnlyShard->getId())->toBe($shard->getId());
});

<?php

declare(strict_types=1);

use Skylence\Shardwise\Exceptions\ShardingException;
use Skylence\Shardwise\Routing\ConsistentHashRing;
use Skylence\Shardwise\Shard;

beforeEach(function (): void {
    $this->ring = new ConsistentHashRing(virtualNodes: 100);
});

it('can add a node to the ring', function (): void {
    $shard = Shard::fromConfig('shard-1', []);

    $this->ring->addNode($shard);

    expect($this->ring->getNodeCount())->toBe(1)
        ->and($this->ring->hasNode('shard-1'))->toBeTrue();
});

it('creates virtual nodes based on weight', function (): void {
    $shard1 = Shard::fromConfig('shard-1', ['weight' => 1]);
    $shard2 = Shard::fromConfig('shard-2', ['weight' => 2]);

    $this->ring->addNode($shard1);
    $this->ring->addNode($shard2);

    // shard-1: 100 virtual nodes (100 * 1)
    // shard-2: 200 virtual nodes (100 * 2)
    expect($this->ring->getVirtualNodeCount())->toBe(300);
});

it('can remove a node from the ring', function (): void {
    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    $this->ring->addNode($shard1);
    $this->ring->addNode($shard2);

    $this->ring->removeNode($shard1);

    expect($this->ring->getNodeCount())->toBe(1)
        ->and($this->ring->hasNode('shard-1'))->toBeFalse()
        ->and($this->ring->hasNode('shard-2'))->toBeTrue();
});

it('throws exception when getting node from empty ring', function (): void {
    $this->ring->getNode('some-key');
})->throws(ShardingException::class);

it('routes keys to nodes consistently', function (): void {
    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    $this->ring->addNode($shard1);
    $this->ring->addNode($shard2);

    // Same key should always route to same shard
    $node1 = $this->ring->getNode('user:123');
    $node2 = $this->ring->getNode('user:123');

    expect($node1->getId())->toBe($node2->getId());
});

it('distributes keys across nodes', function (): void {
    $shard1 = Shard::fromConfig('shard-1', ['weight' => 1]);
    $shard2 = Shard::fromConfig('shard-2', ['weight' => 1]);

    $this->ring->addNode($shard1);
    $this->ring->addNode($shard2);

    // Generate many keys and check distribution
    $keys = array_map(fn ($i) => "key:{$i}", range(1, 1000));
    $distribution = $this->ring->getDistribution($keys);

    // Both shards should have some keys
    expect($distribution['shard-1'])->toBeGreaterThan(0)
        ->and($distribution['shard-2'])->toBeGreaterThan(0);

    // Distribution should be roughly even (within 30% of each other for equal weights)
    $ratio = max($distribution) / max(1, min($distribution));
    expect($ratio)->toBeLessThan(1.5);
});

it('distributes more keys to higher weight nodes', function (): void {
    $shard1 = Shard::fromConfig('shard-1', ['weight' => 1]);
    $shard2 = Shard::fromConfig('shard-2', ['weight' => 3]);

    $this->ring->addNode($shard1);
    $this->ring->addNode($shard2);

    $keys = array_map(fn ($i) => "key:{$i}", range(1, 1000));
    $distribution = $this->ring->getDistribution($keys);

    // shard-2 should have significantly more keys
    expect($distribution['shard-2'])->toBeGreaterThan($distribution['shard-1']);
});

it('can clear the ring', function (): void {
    $shard = Shard::fromConfig('shard-1', []);

    $this->ring->addNode($shard);
    $this->ring->clear();

    expect($this->ring->getNodeCount())->toBe(0)
        ->and($this->ring->getVirtualNodeCount())->toBe(0);
});

it('can get all nodes', function (): void {
    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    $this->ring->addNode($shard1);
    $this->ring->addNode($shard2);

    $nodes = $this->ring->getNodes();

    expect($nodes)->toHaveCount(2)
        ->and(array_keys($nodes))->toBe(['shard-1', 'shard-2']);
});

it('routes integer keys', function (): void {
    $shard = Shard::fromConfig('shard-1', []);
    $this->ring->addNode($shard);

    $node = $this->ring->getNode(12345);

    expect($node->getId())->toBe('shard-1');
});

it('uses different hash algorithms', function (): void {
    $ringXxh = new ConsistentHashRing(100, 'xxh128');
    $ringSha = new ConsistentHashRing(100, 'sha256');

    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    $ringXxh->addNode($shard1);
    $ringXxh->addNode($shard2);

    $ringSha->addNode($shard1);
    $ringSha->addNode($shard2);

    // Both should work
    $nodeXxh = $ringXxh->getNode('test-key');
    $nodeSha = $ringSha->getNode('test-key');

    expect($nodeXxh)->toBeInstanceOf(Shard::class)
        ->and($nodeSha)->toBeInstanceOf(Shard::class);
});

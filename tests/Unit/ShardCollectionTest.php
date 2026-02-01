<?php

declare(strict_types=1);

use Skylence\Shardwise\Shard;
use Skylence\Shardwise\ShardCollection;

beforeEach(function (): void {
    $this->collection = ShardCollection::fromConfig([
        'shard-1' => ['name' => 'Shard 1', 'active' => true, 'read_only' => false, 'weight' => 1],
        'shard-2' => ['name' => 'Shard 2', 'active' => true, 'read_only' => true, 'weight' => 2],
        'shard-3' => ['name' => 'Shard 3', 'active' => false, 'read_only' => false, 'weight' => 3],
    ]);
});

it('can create a collection from config', function (): void {
    expect($this->collection->count())->toBe(3)
        ->and($this->collection->has('shard-1'))->toBeTrue()
        ->and($this->collection->has('shard-2'))->toBeTrue()
        ->and($this->collection->has('shard-3'))->toBeTrue();
});

it('can get a shard by id', function (): void {
    $shard = $this->collection->get('shard-1');

    expect($shard)->not->toBeNull()
        ->and($shard->getId())->toBe('shard-1')
        ->and($shard->getName())->toBe('Shard 1');
});

it('returns null for non-existent shard', function (): void {
    expect($this->collection->get('non-existent'))->toBeNull();
});

it('can add a shard', function (): void {
    $newShard = Shard::fromConfig('shard-4', ['name' => 'Shard 4']);
    $newCollection = $this->collection->add($newShard);

    expect($newCollection->count())->toBe(4)
        ->and($newCollection->has('shard-4'))->toBeTrue()
        ->and($this->collection->count())->toBe(3); // Original unchanged
});

it('can remove a shard', function (): void {
    $newCollection = $this->collection->remove('shard-1');

    expect($newCollection->count())->toBe(2)
        ->and($newCollection->has('shard-1'))->toBeFalse()
        ->and($this->collection->has('shard-1'))->toBeTrue(); // Original unchanged
});

it('can filter active shards', function (): void {
    $active = $this->collection->active();

    expect($active->count())->toBe(2)
        ->and($active->has('shard-1'))->toBeTrue()
        ->and($active->has('shard-2'))->toBeTrue()
        ->and($active->has('shard-3'))->toBeFalse();
});

it('can filter writable shards', function (): void {
    $writable = $this->collection->writable();

    expect($writable->count())->toBe(2)
        ->and($writable->has('shard-1'))->toBeTrue()
        ->and($writable->has('shard-2'))->toBeFalse()
        ->and($writable->has('shard-3'))->toBeTrue();
});

it('can filter read-only shards', function (): void {
    $readOnly = $this->collection->readOnly();

    expect($readOnly->count())->toBe(1)
        ->and($readOnly->has('shard-2'))->toBeTrue();
});

it('can get the first shard', function (): void {
    $first = $this->collection->first();

    expect($first)->not->toBeNull()
        ->and($first->getId())->toBe('shard-1');
});

it('returns null for first on empty collection', function (): void {
    $empty = new ShardCollection;

    expect($empty->first())->toBeNull();
});

it('can get all shard ids', function (): void {
    $ids = $this->collection->ids();

    expect($ids)->toBe(['shard-1', 'shard-2', 'shard-3']);
});

it('can calculate total weight', function (): void {
    expect($this->collection->totalWeight())->toBe(6);
});

it('can check if empty', function (): void {
    expect($this->collection->isEmpty())->toBeFalse()
        ->and($this->collection->isNotEmpty())->toBeTrue()
        ->and((new ShardCollection)->isEmpty())->toBeTrue()
        ->and((new ShardCollection)->isNotEmpty())->toBeFalse();
});

it('is iterable', function (): void {
    $ids = [];
    foreach ($this->collection as $id => $shard) {
        $ids[] = $id;
    }

    expect($ids)->toBe(['shard-1', 'shard-2', 'shard-3']);
});

it('can map over shards', function (): void {
    $names = $this->collection->map(fn ($shard) => $shard->getName());

    expect($names)->toBe([
        'shard-1' => 'Shard 1',
        'shard-2' => 'Shard 2',
        'shard-3' => 'Shard 3',
    ]);
});

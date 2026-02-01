<?php

declare(strict_types=1);

use Skylence\Shardwise\Shard;
use Skylence\Shardwise\ShardContext;

beforeEach(function (): void {
    ShardContext::clear();
});

it('starts with no active context', function (): void {
    expect(ShardContext::current())->toBeNull()
        ->and(ShardContext::active())->toBeFalse()
        ->and(ShardContext::currentId())->toBeNull()
        ->and(ShardContext::depth())->toBe(0);
});

it('can push a shard onto the stack', function (): void {
    $shard = Shard::fromConfig('shard-1', ['name' => 'Test Shard']);

    ShardContext::push($shard);

    expect(ShardContext::current())->toBe($shard)
        ->and(ShardContext::active())->toBeTrue()
        ->and(ShardContext::currentId())->toBe('shard-1')
        ->and(ShardContext::depth())->toBe(1);
});

it('can pop a shard from the stack', function (): void {
    $shard = Shard::fromConfig('shard-1', ['name' => 'Test Shard']);

    ShardContext::push($shard);
    $popped = ShardContext::pop();

    expect($popped)->toBe($shard)
        ->and(ShardContext::current())->toBeNull()
        ->and(ShardContext::active())->toBeFalse()
        ->and(ShardContext::depth())->toBe(0);
});

it('returns null when popping empty stack', function (): void {
    expect(ShardContext::pop())->toBeNull();
});

it('supports nested shard contexts', function (): void {
    $shard1 = Shard::fromConfig('shard-1', ['name' => 'Shard 1']);
    $shard2 = Shard::fromConfig('shard-2', ['name' => 'Shard 2']);

    ShardContext::push($shard1);
    expect(ShardContext::currentId())->toBe('shard-1')
        ->and(ShardContext::depth())->toBe(1);

    ShardContext::push($shard2);
    expect(ShardContext::currentId())->toBe('shard-2')
        ->and(ShardContext::depth())->toBe(2);

    ShardContext::pop();
    expect(ShardContext::currentId())->toBe('shard-1')
        ->and(ShardContext::depth())->toBe(1);

    ShardContext::pop();
    expect(ShardContext::currentId())->toBeNull()
        ->and(ShardContext::depth())->toBe(0);
});

it('can clear the entire stack', function (): void {
    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    ShardContext::push($shard1);
    ShardContext::push($shard2);

    expect(ShardContext::depth())->toBe(2);

    ShardContext::clear();

    expect(ShardContext::depth())->toBe(0)
        ->and(ShardContext::current())->toBeNull();
});

it('can run a callback within a shard context', function (): void {
    $shard = Shard::fromConfig('shard-1', []);

    $result = ShardContext::run($shard, function () {
        return ShardContext::currentId();
    });

    expect($result)->toBe('shard-1')
        ->and(ShardContext::current())->toBeNull();
});

it('restores context even if callback throws', function (): void {
    $shard = Shard::fromConfig('shard-1', []);

    try {
        ShardContext::run($shard, function (): void {
            throw new RuntimeException('Test exception');
        });
    } catch (RuntimeException) {
        // Expected
    }

    expect(ShardContext::current())->toBeNull();
});

it('can check if a shard is in the stack', function (): void {
    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    ShardContext::push($shard1);
    ShardContext::push($shard2);

    expect(ShardContext::contains($shard1))->toBeTrue()
        ->and(ShardContext::contains($shard2))->toBeTrue()
        ->and(ShardContext::contains('shard-1'))->toBeTrue()
        ->and(ShardContext::contains('shard-3'))->toBeFalse();
});

it('can get the full stack', function (): void {
    $shard1 = Shard::fromConfig('shard-1', []);
    $shard2 = Shard::fromConfig('shard-2', []);

    ShardContext::push($shard1);
    ShardContext::push($shard2);

    $stack = ShardContext::stack();

    expect($stack)->toHaveCount(2)
        ->and($stack[0])->toBe($shard1)
        ->and($stack[1])->toBe($shard2);
});

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Testing;

use PHPUnit\Framework\Assert;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardCollection;
use Skylence\Shardwise\ShardContext;
use Skylence\Shardwise\ShardwiseManager;

/**
 * Fake implementation of ShardwiseManager for testing.
 */
final class ShardwiseFake
{
    /**
     * @var array<int, array{shard: ShardInterface, action: string}>
     */
    private array $history = [];

    private ShardCollection $shards;

    public function __construct()
    {
        $this->shards = new ShardCollection;
    }

    /**
     * Add a mock shard for testing.
     */
    public function addMockShard(string $id, ?string $name = null): MockShard
    {
        $shard = MockShard::create($id, $name);
        $this->shards = $this->shards->add($shard);

        return $shard;
    }

    /**
     * Initialize a shard context.
     */
    public function initialize(ShardInterface|string $shard): void
    {
        if (is_string($shard)) {
            $shard = $this->shards->get($shard) ?? MockShard::create($shard);
        }

        ShardContext::push($shard);

        $this->history[] = ['shard' => $shard, 'action' => 'initialize'];
    }

    /**
     * End the current shard context.
     */
    public function end(): void
    {
        $shard = ShardContext::pop();

        if ($shard !== null) {
            $this->history[] = ['shard' => $shard, 'action' => 'end'];
        }
    }

    /**
     * Run a callback in a shard context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(ShardInterface|string $shard, callable $callback): mixed
    {
        $this->initialize($shard);

        try {
            return $callback();
        } finally {
            $this->end();
        }
    }

    /**
     * Get the current shard.
     */
    public function current(): ?ShardInterface
    {
        return ShardContext::current();
    }

    /**
     * Check if there's an active shard context.
     */
    public function active(): bool
    {
        return ShardContext::active();
    }

    /**
     * Get all configured shards.
     */
    public function getShards(): ShardCollection
    {
        return $this->shards;
    }

    /**
     * Get the action history.
     *
     * @return array<int, array{shard: ShardInterface, action: string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Clear the action history.
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    /**
     * Reset the fake completely.
     */
    public function reset(): void
    {
        $this->history = [];
        $this->shards = new ShardCollection;
        ShardContext::clear();
    }

    // Assertion methods

    /**
     * Assert that a shard was initialized.
     */
    public function assertShardInitialized(string $shardId): void
    {
        $found = false;

        foreach ($this->history as $entry) {
            if ($entry['shard']->getId() === $shardId && $entry['action'] === 'initialize') {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, "Expected shard '{$shardId}' to be initialized, but it was not.");
    }

    /**
     * Assert that a shard was not initialized.
     */
    public function assertShardNotInitialized(string $shardId): void
    {
        foreach ($this->history as $entry) {
            if ($entry['shard']->getId() === $shardId && $entry['action'] === 'initialize') {
                Assert::fail("Expected shard '{$shardId}' to not be initialized, but it was.");
            }
        }

        Assert::assertTrue(true);
    }

    /**
     * Assert the number of shard initializations.
     */
    public function assertInitializedCount(int $count): void
    {
        $actual = count(array_filter($this->history, fn (array $e): bool => $e['action'] === 'initialize'));

        Assert::assertSame(
            $count,
            $actual,
            "Expected {$count} shard initializations, but got {$actual}."
        );
    }

    /**
     * Assert no shards were initialized.
     */
    public function assertNothingInitialized(): void
    {
        $this->assertInitializedCount(0);
    }

    /**
     * Assert the current shard is the given one.
     */
    public function assertCurrentShard(string $shardId): void
    {
        $current = ShardContext::current();

        Assert::assertNotNull($current, 'Expected a current shard, but none is active.');
        Assert::assertSame(
            $shardId,
            $current->getId(),
            "Expected current shard to be '{$shardId}', but got '{$current->getId()}'."
        );
    }

    /**
     * Assert no shard context is active.
     */
    public function assertNoCurrentShard(): void
    {
        Assert::assertNull(ShardContext::current(), 'Expected no current shard, but one is active.');
    }
}

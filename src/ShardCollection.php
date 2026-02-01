<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Skylence\Shardwise\Contracts\ShardInterface;
use Traversable;

/**
 * A filterable collection of shards.
 *
 * @implements IteratorAggregate<string, ShardInterface>
 */
final class ShardCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<string, ShardInterface>  $shards
     */
    public function __construct(
        private array $shards = [],
    ) {}

    /**
     * Create a collection from configuration array.
     *
     * @param  array<string, array<string, mixed>>  $config
     */
    public static function fromConfig(array $config): self
    {
        $shards = [];
        foreach ($config as $id => $shardConfig) {
            $shards[$id] = Shard::fromConfig($id, $shardConfig);
        }

        return new self($shards);
    }

    /**
     * Add a shard to the collection.
     */
    public function add(ShardInterface $shard): self
    {
        $shards = $this->shards;
        $shards[$shard->getId()] = $shard;

        return new self($shards);
    }

    /**
     * Remove a shard from the collection.
     */
    public function remove(string $shardId): self
    {
        $shards = $this->shards;
        unset($shards[$shardId]);

        return new self($shards);
    }

    /**
     * Get a shard by ID.
     */
    public function get(string $shardId): ?ShardInterface
    {
        return $this->shards[$shardId] ?? null;
    }

    /**
     * Check if a shard exists in the collection.
     */
    public function has(string $shardId): bool
    {
        return isset($this->shards[$shardId]);
    }

    /**
     * Get the first shard in the collection.
     */
    public function first(): ?ShardInterface
    {
        return $this->shards === [] ? null : reset($this->shards);
    }

    /**
     * Get only active shards.
     */
    public function active(): self
    {
        return $this->filter(fn (ShardInterface $shard): bool => $shard->isActive());
    }

    /**
     * Get only writable (non-read-only) shards.
     */
    public function writable(): self
    {
        return $this->filter(fn (ShardInterface $shard): bool => ! $shard->isReadOnly());
    }

    /**
     * Get only read-only shards.
     */
    public function readOnly(): self
    {
        return $this->filter(fn (ShardInterface $shard): bool => $shard->isReadOnly());
    }

    /**
     * Filter shards using a callback.
     *
     * @param  callable(ShardInterface): bool  $callback
     */
    public function filter(callable $callback): self
    {
        return new self(array_filter($this->shards, $callback));
    }

    /**
     * Map over shards using a callback.
     *
     * @template T
     *
     * @param  callable(ShardInterface): T  $callback
     * @return array<string, T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->shards);
    }

    /**
     * Execute a callback on each shard.
     *
     * @param  callable(ShardInterface, string): void  $callback
     */
    public function each(callable $callback): self
    {
        foreach ($this->shards as $id => $shard) {
            $callback($shard, $id);
        }

        return $this;
    }

    /**
     * Get all shards as an array.
     *
     * @return array<string, ShardInterface>
     */
    public function all(): array
    {
        return $this->shards;
    }

    /**
     * Get all shard IDs.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_keys($this->shards);
    }

    /**
     * Get the total weight of all shards.
     */
    public function totalWeight(): int
    {
        return array_sum($this->map(fn (ShardInterface $shard): int => $shard->getWeight()));
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->shards === [];
    }

    /**
     * Check if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function count(): int
    {
        return count($this->shards);
    }

    /**
     * @return Traversable<string, ShardInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->shards);
    }
}

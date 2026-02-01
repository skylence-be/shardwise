<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Skylence\Shardwise\Concerns\DetectsCrossShardTransactions;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Static context manager for the current shard with stack support for nesting.
 */
final class ShardContext
{
    use DetectsCrossShardTransactions;

    /**
     * Stack of active shard contexts.
     *
     * @var array<int, ShardInterface>
     */
    private static array $stack = [];

    /**
     * Get the current shard context.
     */
    public static function current(): ?ShardInterface
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    /**
     * Check if there is an active shard context.
     */
    public static function active(): bool
    {
        return self::$stack !== [];
    }

    /**
     * Get the current shard ID.
     */
    public static function currentId(): ?string
    {
        return self::current()?->getId();
    }

    /**
     * Push a shard onto the context stack.
     */
    public static function push(ShardInterface $shard): void
    {
        self::$stack[] = $shard;

        // Track shard access for cross-shard transaction detection
        self::recordShardAccess($shard->getId());
    }

    /**
     * Pop the current shard from the context stack.
     */
    public static function pop(): ?ShardInterface
    {
        return self::$stack === [] ? null : array_pop(self::$stack);
    }

    /**
     * Get the depth of the context stack.
     */
    public static function depth(): int
    {
        return count(self::$stack);
    }

    /**
     * Clear the entire context stack.
     */
    public static function clear(): void
    {
        self::$stack = [];
    }

    /**
     * Get all shards in the current stack.
     *
     * @return array<int, ShardInterface>
     */
    public static function stack(): array
    {
        return self::$stack;
    }

    /**
     * Execute a callback within a shard context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(ShardInterface $shard, callable $callback): mixed
    {
        self::push($shard);

        try {
            return $callback();
        } finally {
            self::pop();
        }
    }

    /**
     * Check if a specific shard is in the current context stack.
     */
    public static function contains(ShardInterface|string $shard): bool
    {
        $shardId = $shard instanceof ShardInterface ? $shard->getId() : $shard;

        foreach (self::$stack as $stackedShard) {
            if ($stackedShard->getId() === $shardId) {
                return true;
            }
        }

        return false;
    }
}

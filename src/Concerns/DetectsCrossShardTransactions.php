<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Skylence\Shardwise\Exceptions\ShardingException;

/**
 * Detects and warns about cross-shard transactions.
 *
 * Cross-shard transactions cannot be atomic. When a transaction spans
 * multiple shards, there is no guarantee that all operations will
 * succeed or fail together. Use the Saga pattern for distributed
 * consistency instead.
 *
 * @see https://learn.microsoft.com/en-us/azure/architecture/patterns/saga
 */
trait DetectsCrossShardTransactions
{
    /**
     * Track shards accessed during the current transaction.
     *
     * @var array<string, bool>
     */
    private static array $transactionShards = [];

    /**
     * Whether cross-shard transaction detection is enabled.
     */
    private static bool $detectCrossShardTransactions = true;

    /**
     * Whether to throw exceptions for cross-shard transactions (vs just logging).
     */
    private static bool $strictCrossShardTransactions = false;

    /**
     * Enable cross-shard transaction detection.
     */
    public static function enableCrossShardDetection(): void
    {
        self::$detectCrossShardTransactions = true;
    }

    /**
     * Disable cross-shard transaction detection.
     */
    public static function disableCrossShardDetection(): void
    {
        self::$detectCrossShardTransactions = false;
    }

    /**
     * Enable strict mode (throw exceptions for cross-shard transactions).
     */
    public static function enableStrictCrossShardTransactions(): void
    {
        self::$strictCrossShardTransactions = true;
    }

    /**
     * Disable strict mode (only log warnings).
     */
    public static function disableStrictCrossShardTransactions(): void
    {
        self::$strictCrossShardTransactions = false;
    }

    /**
     * Reset the transaction shard tracking.
     */
    public static function resetTransactionTracking(): void
    {
        self::$transactionShards = [];
    }

    /**
     * Flush all cross-shard transaction detection state.
     *
     * This is critical for long-running processes (Octane, Swoole, RoadRunner)
     * to prevent cross-request data leaks.
     */
    public static function flushTransactionState(): void
    {
        self::$transactionShards = [];
        self::$detectCrossShardTransactions = true;
        self::$strictCrossShardTransactions = false;
    }

    /**
     * Get the shards accessed in the current transaction.
     *
     * @return array<int, string>
     */
    public static function getTransactionShards(): array
    {
        return array_keys(self::$transactionShards);
    }

    /**
     * Check if the current transaction spans multiple shards.
     */
    public static function isCrossShardTransaction(): bool
    {
        return count(self::$transactionShards) > 1;
    }

    /**
     * Record that a shard was accessed during a transaction.
     *
     * @throws ShardingException If strict mode is enabled and multiple shards are accessed
     */
    protected static function recordShardAccess(string $shardId): void
    {
        if (! self::$detectCrossShardTransactions) {
            return;
        }

        // Only track during active transactions
        if (DB::transactionLevel() === 0) {
            self::$transactionShards = [];

            return;
        }

        $previousCount = count(self::$transactionShards);
        self::$transactionShards[$shardId] = true;
        $currentCount = count(self::$transactionShards);

        // Detect when a second shard is accessed
        if ($previousCount > 0 && $currentCount > $previousCount) {
            $shards = array_keys(self::$transactionShards);
            $message = sprintf(
                'Cross-shard transaction detected: Operations span shards [%s]. '.
                'Cross-shard transactions are NOT atomic and may leave data in an inconsistent state. '.
                'Consider using the Saga pattern for distributed consistency.',
                implode(', ', $shards)
            );

            if (self::$strictCrossShardTransactions) {
                throw ShardingException::crossShardTransaction($shards);
            }

            Log::warning($message, [
                'shards' => $shards,
                'transaction_level' => DB::transactionLevel(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Contracts;

/**
 * Represents a single database shard.
 */
interface ShardInterface
{
    /**
     * Get the unique identifier for this shard.
     */
    public function getId(): string;

    /**
     * Get the display name for this shard.
     */
    public function getName(): string;

    /**
     * Get the database connection name for this shard.
     */
    public function getConnectionName(): string;

    /**
     * Get the database connection configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConnectionConfig(): array;

    /**
     * Get the weight for load balancing (higher = more traffic).
     */
    public function getWeight(): int;

    /**
     * Check if this shard is currently active and accepting connections.
     */
    public function isActive(): bool;

    /**
     * Check if this shard is read-only.
     */
    public function isReadOnly(): bool;

    /**
     * Get custom metadata associated with this shard.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}

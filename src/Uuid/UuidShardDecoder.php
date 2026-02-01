<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Uuid;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardCollection;

/**
 * Decodes shard information from UUIDs.
 */
final class UuidShardDecoder
{
    public function __construct(
        private readonly ShardAwareUuidFactory $factory,
        private readonly ShardCollection $shards,
    ) {}

    /**
     * Create from configuration.
     */
    public static function fromConfig(ShardCollection $shards): self
    {
        return new self(ShardAwareUuidFactory::fromConfig(), $shards);
    }

    /**
     * Decode a UUID and resolve the associated shard.
     */
    public function decode(string|UuidInterface $uuid): ?ShardInterface
    {
        if (is_string($uuid)) {
            if (! Uuid::isValid($uuid)) {
                return null;
            }
            $uuid = Uuid::fromString($uuid);
        }

        $shardId = $this->factory->extractShardIdFromUuid($uuid);

        if ($shardId === null) {
            return null;
        }

        return $this->resolveShardFromNumericId($shardId);
    }

    /**
     * Get the numeric shard ID from a UUID.
     */
    public function getShardIdFromUuid(string|UuidInterface $uuid): ?int
    {
        return $this->factory->extractShardIdFromUuid($uuid);
    }

    /**
     * Check if a UUID has embedded shard information.
     */
    public function hasShardInfo(string|UuidInterface $uuid): bool
    {
        return $this->factory->extractShardIdFromUuid($uuid) !== null;
    }

    /**
     * Parse a UUID to a ShardAwareUuid instance.
     */
    public function parse(string $uuid): ShardAwareUuid
    {
        return $this->factory->parse($uuid);
    }

    /**
     * Resolve a shard from its numeric ID.
     */
    private function resolveShardFromNumericId(int $numericId): ?ShardInterface
    {
        // First, try direct match with string ID
        $shard = $this->shards->get((string) $numericId);
        if ($shard !== null) {
            return $shard;
        }

        // Try matching shards with numeric suffix (e.g., "shard-1" -> 1)
        foreach ($this->shards as $shard) {
            $shardId = $shard->getId();

            // Check if the shard ID contains the numeric ID
            if (preg_match('/(\d+)/', $shardId, $matches)) {
                if ((int) $matches[1] === $numericId) {
                    return $shard;
                }
            }
        }

        // Try matching by index position
        $shardIds = $this->shards->ids();
        if (isset($shardIds[$numericId])) {
            return $this->shards->get($shardIds[$numericId]);
        }

        return null;
    }
}

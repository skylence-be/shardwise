<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Testing;

use Ramsey\Uuid\Uuid;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Uuid\ShardAwareUuid;

/**
 * Deterministic UUID factory for testing.
 *
 * Generates predictable UUIDs based on a sequence counter.
 */
final class DeterministicUuidFactory
{
    private int $sequence = 0;

    /**
     * @var array<int, string>
     */
    private array $predefinedUuids = [];

    public function __construct(
        private readonly int $shardBits = 10,
        private readonly bool $embedMetadata = true,
    ) {}

    /**
     * Generate a deterministic UUID.
     */
    public function generate(?ShardInterface $shard = null): ShardAwareUuid
    {
        // Use predefined UUIDs first
        if (isset($this->predefinedUuids[$this->sequence])) {
            $uuid = Uuid::fromString($this->predefinedUuids[$this->sequence]);
            $this->sequence++;

            return new ShardAwareUuid($uuid, $shard !== null ? $this->getNumericShardId($shard) : null);
        }

        // Generate deterministic UUID based on sequence
        $uuid = $this->generateDeterministicUuid($this->sequence++);

        return new ShardAwareUuid($uuid, $shard !== null ? $this->getNumericShardId($shard) : null);
    }

    /**
     * Generate a deterministic UUID string.
     */
    public function generateString(?ShardInterface $shard = null): string
    {
        return $this->generate($shard)->toString();
    }

    /**
     * Parse a UUID string.
     */
    public function parse(string $uuid): ShardAwareUuid
    {
        return new ShardAwareUuid(Uuid::fromString($uuid), null);
    }

    /**
     * Set predefined UUIDs to return in sequence.
     *
     * @param  array<int, string>  $uuids
     * @return $this
     */
    public function setUuids(array $uuids): self
    {
        $this->predefinedUuids = array_values($uuids);
        $this->sequence = 0;

        return $this;
    }

    /**
     * Add a predefined UUID.
     *
     * @return $this
     */
    public function addUuid(string $uuid): self
    {
        $this->predefinedUuids[] = $uuid;

        return $this;
    }

    /**
     * Reset the sequence counter.
     *
     * @return $this
     */
    public function reset(): self
    {
        $this->sequence = 0;

        return $this;
    }

    /**
     * Get the current sequence number.
     */
    public function getSequence(): int
    {
        return $this->sequence;
    }

    /**
     * Get the maximum shard ID supported by the current bit configuration.
     */
    public function getMaxShardId(): int
    {
        return (1 << $this->shardBits) - 1;
    }

    /**
     * Generate a deterministic UUID based on a sequence number.
     */
    private function generateDeterministicUuid(int $sequence): \Ramsey\Uuid\UuidInterface
    {
        // Create a deterministic UUID v4 pattern
        $hex = mb_str_pad(dechex($sequence), 32, '0', STR_PAD_LEFT);

        // Format as UUID (8-4-4-4-12)
        $uuid = sprintf(
            '%s-%s-4%s-%s%s-%s',
            mb_substr($hex, 0, 8),
            mb_substr($hex, 8, 4),
            mb_substr($hex, 13, 3),
            dechex(8 | (hexdec(mb_substr($hex, 16, 1)) & 3)), // Variant bits
            mb_substr($hex, 17, 3),
            mb_substr($hex, 20, 12)
        );

        return Uuid::fromString($uuid);
    }

    /**
     * Get numeric shard ID from a shard.
     */
    private function getNumericShardId(ShardInterface $shard): int
    {
        $id = $shard->getId();

        if (is_numeric($id)) {
            return (int) $id;
        }

        if (preg_match('/(\d+)/', $id, $matches)) {
            return (int) $matches[1];
        }

        return abs(crc32($id)) % ($this->getMaxShardId() + 1);
    }
}

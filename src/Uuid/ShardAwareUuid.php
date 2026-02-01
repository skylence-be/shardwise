<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Uuid;

use Ramsey\Uuid\UuidInterface;
use Stringable;

/**
 * UUID wrapper with embedded shard metadata.
 */
final readonly class ShardAwareUuid implements Stringable
{
    public function __construct(
        private UuidInterface $uuid,
        private ?int $shardId = null,
    ) {}

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Get the underlying UUID.
     */
    public function getUuid(): UuidInterface
    {
        return $this->uuid;
    }

    /**
     * Get the embedded shard ID.
     */
    public function getShardId(): ?int
    {
        return $this->shardId;
    }

    /**
     * Check if the UUID has an embedded shard ID.
     */
    public function hasShardId(): bool
    {
        return $this->shardId !== null;
    }

    /**
     * Get the UUID string representation.
     */
    public function toString(): string
    {
        return $this->uuid->toString();
    }

    /**
     * Get the UUID bytes.
     */
    public function getBytes(): string
    {
        return $this->uuid->getBytes();
    }

    /**
     * Get the UUID hex representation (without dashes).
     */
    public function getHex(): string
    {
        return $this->uuid->getHex()->toString();
    }

    /**
     * Get the timestamp from a UUID v7.
     */
    public function getTimestamp(): ?int
    {
        if ($this->uuid->getVersion() !== 7) {
            return null;
        }

        // Extract timestamp from UUID v7 (first 48 bits)
        $hex = $this->uuid->getHex()->toString();
        $timestampHex = mb_substr($hex, 0, 12);

        return (int) hexdec($timestampHex);
    }

    /**
     * Check equality with another UUID.
     */
    public function equals(self|UuidInterface|string $other): bool
    {
        if ($other instanceof self) {
            return $this->uuid->equals($other->uuid);
        }

        if ($other instanceof UuidInterface) {
            return $this->uuid->equals($other);
        }

        return $this->uuid->toString() === $other;
    }
}

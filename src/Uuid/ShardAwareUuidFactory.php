<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Uuid;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardContext;

/**
 * Factory for generating shard-aware UUIDs with embedded shard metadata.
 */
final class ShardAwareUuidFactory
{
    private const int DEFAULT_SHARD_BITS = 10;

    private const int MIN_SHARD_BITS = 1;

    private const int MAX_SHARD_BITS = 16;

    public function __construct(
        private readonly int $shardBits = self::DEFAULT_SHARD_BITS,
        private readonly bool $embedMetadata = true,
    ) {
        if ($shardBits < self::MIN_SHARD_BITS || $shardBits > self::MAX_SHARD_BITS) {
            throw new InvalidArgumentException(
                sprintf(
                    'shardBits must be between %d and %d, got %d',
                    self::MIN_SHARD_BITS,
                    self::MAX_SHARD_BITS,
                    $shardBits
                )
            );
        }
    }

    /**
     * Create from configuration.
     */
    public static function fromConfig(): self
    {
        /** @var int $shardBits */
        $shardBits = config('shardwise.uuid.shard_bits', self::DEFAULT_SHARD_BITS);

        /** @var bool $embedMetadata */
        $embedMetadata = config('shardwise.uuid.embed_shard_metadata', true);

        return new self($shardBits, $embedMetadata);
    }

    /**
     * Generate a new shard-aware UUID.
     */
    public function generate(?ShardInterface $shard = null): ShardAwareUuid
    {
        $shard ??= ShardContext::current();

        // Generate a UUID v7
        $uuid = Uuid::uuid7();

        if (! $this->embedMetadata || $shard === null) {
            return new ShardAwareUuid($uuid, null);
        }

        $shardId = $this->getNumericShardId($shard);

        // Embed shard ID in the UUID
        $modifiedUuid = $this->embedShardId($uuid, $shardId);

        return new ShardAwareUuid($modifiedUuid, $shardId);
    }

    /**
     * Generate a UUID string.
     */
    public function generateString(?ShardInterface $shard = null): string
    {
        return $this->generate($shard)->toString();
    }

    /**
     * Parse a UUID string and extract shard metadata.
     */
    public function parse(string $uuid): ShardAwareUuid
    {
        $parsedUuid = Uuid::fromString($uuid);

        $shardId = $this->embedMetadata
            ? $this->extractShardId($parsedUuid)
            : null;

        return new ShardAwareUuid($parsedUuid, $shardId);
    }

    /**
     * Extract the shard ID from a UUID.
     */
    public function extractShardIdFromUuid(string|UuidInterface $uuid): ?int
    {
        if (is_string($uuid)) {
            $uuid = Uuid::fromString($uuid);
        }

        return $this->extractShardId($uuid);
    }

    /**
     * Get the maximum shard ID supported by the current bit configuration.
     */
    public function getMaxShardId(): int
    {
        return (1 << $this->shardBits) - 1;
    }

    /**
     * Get the number of bits used for the shard ID.
     */
    public function getShardBits(): int
    {
        return $this->shardBits;
    }

    /**
     * Check if metadata embedding is enabled.
     */
    public function isEmbeddingEnabled(): bool
    {
        return $this->embedMetadata;
    }

    /**
     * Embed the shard ID into the UUID.
     *
     * We use the last 10 bits (or configured shard_bits) of the random
     * portion of the UUID v7 to store the shard ID.
     *
     * @throws InvalidArgumentException If shard ID exceeds maximum for configured bits
     */
    private function embedShardId(UuidInterface $uuid, int $shardId): UuidInterface
    {
        $maxShardId = $this->getMaxShardId();

        if ($shardId > $maxShardId) {
            throw new InvalidArgumentException(
                sprintf(
                    'Shard ID %d exceeds maximum %d for %d bits. Consider increasing shardBits configuration.',
                    $shardId,
                    $maxShardId,
                    $this->shardBits
                )
            );
        }

        $bytes = $uuid->getBytes();

        // Get the last 2 bytes, clear the shard bits, and set them
        // Note: We use substr() instead of mb_substr() for binary data
        $lastTwoBytes = unpack('n', substr($bytes, -2));
        if ($lastTwoBytes === false) {
            return $uuid;
        }

        $value = $lastTwoBytes[1];
        $value = ($value & ~$maxShardId) | $shardId;

        // Reconstruct the bytes
        $newLastBytes = pack('n', $value);
        $newBytes = substr($bytes, 0, -2).$newLastBytes;

        return Uuid::fromBytes($newBytes);
    }

    /**
     * Extract the shard ID from a UUID.
     */
    private function extractShardId(UuidInterface $uuid): ?int
    {
        $bytes = $uuid->getBytes();

        // Extract from the last 2 bytes
        // Note: We use substr() instead of mb_substr() for binary data
        $lastTwoBytes = unpack('n', substr($bytes, -2));
        if ($lastTwoBytes === false) {
            return null;
        }

        $value = $lastTwoBytes[1];
        $mask = $this->getMaxShardId();

        return $value & $mask;
    }

    /**
     * Convert a shard to its numeric ID.
     */
    private function getNumericShardId(ShardInterface $shard): int
    {
        $id = $shard->getId();

        // If the ID is numeric, use it directly
        if (is_numeric($id)) {
            return (int) $id;
        }

        // Otherwise, extract a number from the ID or hash it
        if (preg_match('/(\d+)/', $id, $matches)) {
            return (int) $matches[1];
        }

        // Fall back to a hash
        return abs(crc32($id)) % ($this->getMaxShardId() + 1);
    }
}

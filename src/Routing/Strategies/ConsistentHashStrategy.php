<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Routing\Strategies;

use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardStrategyInterface;
use Skylence\Shardwise\Routing\ConsistentHashRing;
use Skylence\Shardwise\ShardCollection;

/**
 * Routing strategy using consistent hashing for even distribution.
 */
final class ConsistentHashStrategy implements ShardStrategyInterface
{
    private ?ConsistentHashRing $ring = null;

    /**
     * Hash of the last shard collection's content for change detection.
     */
    private ?string $lastShardsHash = null;

    public function __construct(
        private readonly int $virtualNodes = 150,
        private readonly string $hashAlgorithm = 'xxh128',
    ) {}

    /**
     * Create from configuration.
     */
    public static function fromConfig(): self
    {
        /** @var int $virtualNodes */
        $virtualNodes = config('shardwise.consistent_hash.virtual_nodes', 150);

        /** @var string $hashAlgorithm */
        $hashAlgorithm = config('shardwise.consistent_hash.hash_algorithm', 'xxh128');

        return new self($virtualNodes, $hashAlgorithm);
    }

    /**
     * Get the shard for the given key.
     */
    public function getShard(string|int $key, ShardCollection $shards): ShardInterface
    {
        $ring = $this->getRing($shards);

        return $ring->getNode($key);
    }

    /**
     * Get the strategy name identifier.
     */
    public function getName(): string
    {
        return 'consistent_hash';
    }

    /**
     * Check if this strategy supports the given key type.
     */
    public function supportsKey(mixed $key): bool
    {
        return is_string($key) || is_int($key);
    }

    /**
     * Get the number of virtual nodes.
     */
    public function getVirtualNodes(): int
    {
        return $this->virtualNodes;
    }

    /**
     * Get the hash algorithm.
     */
    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }

    /**
     * Get the hash ring, rebuilding if shards have changed.
     */
    private function getRing(ShardCollection $shards): ConsistentHashRing
    {
        $currentHash = $this->computeCollectionHash($shards);

        // Rebuild ring if shards have changed (content-based comparison)
        if ($this->ring === null || $this->lastShardsHash !== $currentHash) {
            $this->ring = new ConsistentHashRing($this->virtualNodes, $this->hashAlgorithm);

            // Use bulk addNodes for better performance (sorts only once)
            $this->ring->addNodes($shards);

            $this->lastShardsHash = $currentHash;
        }

        return $this->ring;
    }

    /**
     * Compute a hash of the shard collection's content for change detection.
     *
     * Includes shard IDs and weights since both affect distribution.
     */
    private function computeCollectionHash(ShardCollection $shards): string
    {
        $parts = [];

        foreach ($shards as $shard) {
            $parts[] = $shard->getId().':'.$shard->getWeight();
        }

        sort($parts);

        return md5(implode('|', $parts));
    }
}

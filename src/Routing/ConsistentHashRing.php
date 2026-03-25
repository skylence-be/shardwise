<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Routing;

use InvalidArgumentException;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Exceptions\ShardingException;

/**
 * Consistent hash ring implementation with virtual nodes for even distribution.
 *
 * Virtual nodes are weighted by shard weight, allowing more powerful shards
 * to receive proportionally more traffic. A shard with weight 2 will have
 * twice as many virtual nodes as a shard with weight 1.
 */
final class ConsistentHashRing
{
    /**
     * The hash ring mapping positions to shard IDs.
     *
     * @var array<string, string>
     */
    private array $ring = [];

    /**
     * Sorted positions in the ring.
     *
     * @var array<int, string>
     */
    private array $sortedPositions = [];

    /**
     * Map of shard ID to shard instance.
     *
     * @var array<string, ShardInterface>
     */
    private array $shards = [];

    /**
     * Track the number of virtual nodes per shard (for removal).
     *
     * @var array<string, int>
     */
    private array $virtualNodeCounts = [];

    public function __construct(
        private readonly int $virtualNodes = 150,
        private readonly string $hashAlgorithm = 'xxh128',
    ) {
        if (! in_array($hashAlgorithm, hash_algos(), true)) {
            throw new InvalidArgumentException("Hash algorithm '{$hashAlgorithm}' is not available.");
        }
    }

    /**
     * Add a shard to the ring.
     *
     * The number of virtual nodes is multiplied by the shard's weight,
     * allowing weighted distribution across heterogeneous hardware.
     */
    public function addNode(ShardInterface $shard): void
    {
        $shardId = $shard->getId();
        $this->shards[$shardId] = $shard;

        // Add virtual nodes based on weight (weighted virtual nodes)
        $totalVirtualNodes = $this->virtualNodes * max(1, $shard->getWeight());
        $this->virtualNodeCounts[$shardId] = $totalVirtualNodes;

        for ($i = 0; $i < $totalVirtualNodes; $i++) {
            $position = $this->hash("{$shardId}:{$i}");
            $this->ring[$position] = $shardId;
        }

        $this->sortPositions();
    }

    /**
     * Add multiple shards to the ring at once.
     *
     * More efficient than addNode() in a loop as it only sorts once.
     *
     * @param  iterable<ShardInterface>  $shards
     */
    public function addNodes(iterable $shards): void
    {
        foreach ($shards as $shard) {
            $shardId = $shard->getId();
            $this->shards[$shardId] = $shard;

            $totalVirtualNodes = $this->virtualNodes * max(1, $shard->getWeight());
            $this->virtualNodeCounts[$shardId] = $totalVirtualNodes;

            for ($i = 0; $i < $totalVirtualNodes; $i++) {
                $position = $this->hash("{$shardId}:{$i}");
                $this->ring[$position] = $shardId;
            }
        }

        $this->sortPositions();
    }

    /**
     * Remove a shard from the ring.
     */
    public function removeNode(ShardInterface $shard): void
    {
        $shardId = $shard->getId();
        $totalVirtualNodes = $this->virtualNodeCounts[$shardId] ?? $this->virtualNodes;

        // Remove virtual nodes by regenerating their positions
        for ($i = 0; $i < $totalVirtualNodes; $i++) {
            $position = $this->hash("{$shardId}:{$i}");
            unset($this->ring[$position]);
        }

        unset($this->shards[$shardId], $this->virtualNodeCounts[$shardId]);

        $this->sortPositions();
    }

    /**
     * Get the shard for a given key.
     *
     * @throws ShardingException
     */
    public function getNode(string|int $key): ShardInterface
    {
        if ($this->ring === []) {
            throw ShardingException::noActiveShards();
        }

        $hash = $this->hash((string) $key);

        // Binary search for the first position >= hash
        $position = $this->findPosition($hash);

        $shardId = $this->ring[$position];

        return $this->shards[$shardId];
    }

    /**
     * Get the number of nodes (shards) in the ring.
     */
    public function getNodeCount(): int
    {
        return count($this->shards);
    }

    /**
     * Get the total number of virtual nodes in the ring.
     */
    public function getVirtualNodeCount(): int
    {
        return count($this->ring);
    }

    /**
     * Get all shards in the ring.
     *
     * @return array<string, ShardInterface>
     */
    public function getNodes(): array
    {
        return $this->shards;
    }

    /**
     * Check if a shard exists in the ring.
     */
    public function hasNode(string $shardId): bool
    {
        return isset($this->shards[$shardId]);
    }

    /**
     * Clear the ring.
     */
    public function clear(): void
    {
        $this->ring = [];
        $this->sortedPositions = [];
        $this->shards = [];
    }

    /**
     * Get the distribution of keys across shards (for testing/debugging).
     *
     * @param  array<int, string|int>  $keys
     * @return array<string, int>
     */
    public function getDistribution(array $keys): array
    {
        $distribution = [];

        foreach ($this->shards as $shardId => $shard) {
            $distribution[$shardId] = 0;
        }

        foreach ($keys as $key) {
            try {
                $shard = $this->getNode($key);
                $distribution[$shard->getId()]++;
            } catch (ShardingException) {
                // Ignore if no shards
            }
        }

        return $distribution;
    }

    /**
     * Hash a key using the configured algorithm.
     */
    private function hash(string $key): string
    {
        return match ($this->hashAlgorithm) {
            'xxh128' => hash('xxh128', $key),
            'xxh64' => hash('xxh64', $key),
            'sha256' => hash('sha256', $key),
            'md5' => md5($key),
            default => hash('xxh128', $key),
        };
    }

    /**
     * Sort the positions in the ring.
     */
    private function sortPositions(): void
    {
        $this->sortedPositions = array_keys($this->ring);
        sort($this->sortedPositions);
    }

    /**
     * Find the position in the ring for a hash using binary search.
     */
    private function findPosition(string $hash): string
    {
        $positions = $this->sortedPositions;
        $count = count($positions);

        if ($count === 0) {
            return '';
        }

        // Binary search for the first position >= hash
        $low = 0;
        $high = $count - 1;

        while ($low < $high) {
            $mid = (int) (($low + $high) / 2);

            if ($positions[$mid] < $hash) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        // If hash is greater than all positions, wrap around to first
        if ($positions[$low] < $hash) {
            return $positions[0];
        }

        return $positions[$low];
    }
}

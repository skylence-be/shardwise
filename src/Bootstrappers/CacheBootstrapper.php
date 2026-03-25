<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Skylence\Shardwise\Contracts\BootstrapperInterface;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Bootstrapper that prefixes cache keys with the shard ID.
 */
final class CacheBootstrapper implements BootstrapperInterface
{
    private ?string $previousPrefix = null;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * Bootstrap the shard context by prefixing cache keys.
     */
    public function bootstrap(ShardInterface $shard): void
    {
        $store = $this->cacheManager->store();

        if ($store instanceof Repository) {
            $currentPrefix = $this->getPrefix($store);
            $this->previousPrefix = $currentPrefix;
            $this->setPrefix($store, $currentPrefix."shard:{$shard->getId()}:");
        }
    }

    /**
     * Revert to the previous cache prefix.
     */
    public function revert(): void
    {
        $store = $this->cacheManager->store();

        if ($store instanceof Repository && $this->previousPrefix !== null) {
            $this->setPrefix($store, $this->previousPrefix);
            $this->previousPrefix = null;
        }
    }

    /**
     * Get the current cache prefix.
     */
    private function getPrefix(Repository $store): ?string
    {
        $innerStore = $store->getStore();

        if (method_exists($innerStore, 'getPrefix')) {
            return $innerStore->getPrefix();
        }

        return null;
    }

    /**
     * Set the cache prefix.
     */
    private function setPrefix(Repository $store, ?string $prefix): void
    {
        $innerStore = $store->getStore();

        if (method_exists($innerStore, 'setPrefix')) {
            $innerStore->setPrefix($prefix ?? '');
        }
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Illuminate\Contracts\Container\Container;
use Skylence\Shardwise\Contracts\BootstrapperInterface;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\Contracts\ShardRouterInterface;
use Skylence\Shardwise\Events\ShardBootstrapped;
use Skylence\Shardwise\Events\ShardEnded;
use Skylence\Shardwise\Events\ShardInitialized;
use Skylence\Shardwise\Exceptions\ShardNotFoundException;
use Skylence\Shardwise\Metrics\ShardMetrics;

/**
 * Main orchestrator for shard management and lifecycle.
 */
final class ShardwiseManager
{
    private ShardCollection $shards;

    /**
     * Stack of bootstrappers for each nested context level.
     *
     * @var array<int, array<int, BootstrapperInterface>>
     */
    private array $bootstrapperStack = [];

    public function __construct(
        private readonly Container $container,
        private readonly ShardRouterInterface $router,
    ) {
        $this->shards = new ShardCollection;
    }

    /**
     * Initialize shard context with the given shard.
     */
    public function initialize(ShardInterface|string $shard): void
    {
        if (is_string($shard)) {
            $shard = $this->getShard($shard);
        }

        ShardContext::push($shard);

        event(new ShardInitialized($shard));

        $this->runBootstrappers($shard);

        event(new ShardBootstrapped($shard));
    }

    /**
     * End the current shard context.
     */
    public function end(): void
    {
        $shard = ShardContext::pop();

        if ($shard === null) {
            return;
        }

        $this->revertBootstrappers();

        event(new ShardEnded($shard));
    }

    /**
     * Execute a callback within a shard context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(ShardInterface|string $shard, callable $callback): mixed
    {
        if (is_string($shard)) {
            $shard = $this->getShard($shard);
        }

        $this->initialize($shard);

        $startTime = microtime(true);

        try {
            return $callback();
        } finally {
            $duration = (microtime(true) - $startTime) * 1000;
            ShardMetrics::getInstance()->recordQuery($shard, $duration);
            $this->end();
        }
    }

    /**
     * Get the metrics instance.
     */
    public function metrics(): ShardMetrics
    {
        return ShardMetrics::getInstance();
    }

    /**
     * Execute a callback on all shards.
     *
     * @template T
     *
     * @param  callable(ShardInterface): T  $callback
     * @return array<string, T>
     */
    public function runOnAllShards(callable $callback): array
    {
        $results = [];

        foreach ($this->shards->active() as $shard) {
            $results[$shard->getId()] = $this->run($shard, fn (): mixed => $callback($shard));
        }

        return $results;
    }

    /**
     * Get the current shard.
     */
    public function current(): ?ShardInterface
    {
        return ShardContext::current();
    }

    /**
     * Check if there's an active shard context.
     */
    public function active(): bool
    {
        return ShardContext::active();
    }

    /**
     * Get a shard by ID.
     *
     * @throws ShardNotFoundException
     */
    public function getShard(string $shardId): ShardInterface
    {
        $shard = $this->shards->get($shardId);

        if ($shard === null) {
            throw ShardNotFoundException::forId($shardId);
        }

        return $shard;
    }

    /**
     * Get all configured shards.
     */
    public function getShards(): ShardCollection
    {
        return $this->shards;
    }

    /**
     * Set the shard collection.
     */
    public function setShards(ShardCollection $shards): void
    {
        $this->shards = $shards;
    }

    /**
     * Add a shard to the collection.
     */
    public function addShard(ShardInterface $shard): void
    {
        $this->shards = $this->shards->add($shard);
    }

    /**
     * Get the router instance.
     */
    public function getRouter(): ShardRouterInterface
    {
        return $this->router;
    }

    /**
     * Route a key to the appropriate shard.
     */
    public function route(string|int $key): ShardInterface
    {
        return $this->router->route($key);
    }

    /**
     * Route a key for a specific table.
     */
    public function routeForTable(string $table, string|int $key): ShardInterface
    {
        return $this->router->routeForTable($table, $key);
    }

    /**
     * Run all bootstrappers for the shard.
     */
    private function runBootstrappers(ShardInterface $shard): void
    {
        /** @var array<class-string<BootstrapperInterface>> $bootstrapperClasses */
        $bootstrapperClasses = config('shardwise.bootstrappers', []);

        $contextBootstrappers = [];

        foreach ($bootstrapperClasses as $bootstrapperClass) {
            /** @var BootstrapperInterface $bootstrapper */
            $bootstrapper = $this->container->make($bootstrapperClass);
            $bootstrapper->bootstrap($shard);
            $contextBootstrappers[] = $bootstrapper;
        }

        // Push this context's bootstrappers onto the stack
        $this->bootstrapperStack[] = $contextBootstrappers;
    }

    /**
     * Revert bootstrappers for the current context level only.
     */
    private function revertBootstrappers(): void
    {
        // Pop only the current context's bootstrappers
        $contextBootstrappers = array_pop($this->bootstrapperStack);

        if ($contextBootstrappers === null) {
            return;
        }

        // Revert in reverse order
        foreach (array_reverse($contextBootstrappers) as $bootstrapper) {
            $bootstrapper->revert();
        }
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Skylence\Shardwise\Commands\FdwSetupCommand;
use Skylence\Shardwise\Commands\FdwTablesCommand;
use Skylence\Shardwise\Commands\ShardHealthCommand;
use Skylence\Shardwise\Commands\ShardListCommand;
use Skylence\Shardwise\Commands\ShardMakeCommand;
use Skylence\Shardwise\Commands\ShardMigrateCommand;
use Skylence\Shardwise\Commands\ShardSeedCommand;
use Skylence\Shardwise\Commands\ShardStatusCommand;
use Skylence\Shardwise\Connections\ConnectionPool;
use Skylence\Shardwise\Connections\ShardConnectionFactory;
use Skylence\Shardwise\Connections\ShardDatabaseManager;
use Skylence\Shardwise\Contracts\ShardRouterInterface;
use Skylence\Shardwise\Contracts\ShardStrategyInterface;
use Skylence\Shardwise\Health\ShardHealthChecker;
use Skylence\Shardwise\Macros\QueryBuilderMacros;
use Skylence\Shardwise\Metrics\ShardMetrics;
use Skylence\Shardwise\Migrations\ShardMigrationRepository;
use Skylence\Shardwise\Migrations\ShardMigrator;
use Skylence\Shardwise\Query\CrossShardBuilder;
use Skylence\Shardwise\Routing\ShardRouter;
use Skylence\Shardwise\Routing\Strategies\ConsistentHashStrategy;
use Skylence\Shardwise\Routing\Strategies\ModuloStrategy;
use Skylence\Shardwise\Routing\Strategies\RangeStrategy;
use Skylence\Shardwise\Routing\TableGroupResolver;
use Skylence\Shardwise\Uuid\ShardAwareUuidFactory;
use Skylence\Shardwise\Uuid\UuidShardDecoder;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ShardwiseServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('shardwise')
            ->hasConfigFile('shardwise')
            ->hasCommands([
                ShardListCommand::class,
                ShardMigrateCommand::class,
                ShardHealthCommand::class,
                ShardStatusCommand::class,
                ShardSeedCommand::class,
                ShardMakeCommand::class,
                FdwSetupCommand::class,
                FdwTablesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerConnections();
        $this->registerRouting();
        $this->registerUuid();
        $this->registerManager();
        $this->registerHealth();
        $this->registerMigrations();
    }

    public function packageBooted(): void
    {
        $this->bootShards();
        $this->registerMacros();
        $this->registerQueueIntegration();
        $this->registerOctaneCompatibility();
    }

    private function registerConnections(): void
    {
        $this->app->singleton(ShardConnectionFactory::class, function (Container $app): ShardConnectionFactory {
            return new ShardConnectionFactory(
                $app->make(DatabaseManager::class)
            );
        });

        $this->app->singleton(ShardDatabaseManager::class, function (Container $app): ShardDatabaseManager {
            return new ShardDatabaseManager(
                $app->make(DatabaseManager::class),
                $app->make(ShardConnectionFactory::class)
            );
        });

        $this->app->singleton(ConnectionPool::class, function (Container $app): ConnectionPool {
            /** @var int $maxConnections */
            $maxConnections = config('shardwise.connection_pool.max_connections', 10);

            /** @var int $idleTimeout */
            $idleTimeout = config('shardwise.connection_pool.idle_timeout', 60);

            return new ConnectionPool(
                $app->make(DatabaseManager::class),
                $app->make(ShardConnectionFactory::class),
                $maxConnections,
                $idleTimeout
            );
        });
    }

    private function registerRouting(): void
    {
        $this->app->singleton(TableGroupResolver::class, function (): TableGroupResolver {
            return TableGroupResolver::fromConfig();
        });

        $this->app->singleton(ShardStrategyInterface::class, function (): ShardStrategyInterface {
            /** @var string $strategyName */
            $strategyName = config('shardwise.default_strategy', 'consistent_hash');

            return match ($strategyName) {
                'consistent_hash' => ConsistentHashStrategy::fromConfig(),
                'range' => new RangeStrategy,
                'modulo' => new ModuloStrategy,
                default => ConsistentHashStrategy::fromConfig(),
            };
        });

        $this->app->singleton(ShardRouterInterface::class, function (Container $app): ShardRouterInterface {
            return new ShardRouter(
                $app->make(ShardwiseManager::class)->getShards(),
                $app->make(ShardStrategyInterface::class),
                $app->make(TableGroupResolver::class)
            );
        });
    }

    private function registerUuid(): void
    {
        $this->app->singleton(ShardAwareUuidFactory::class, function (): ShardAwareUuidFactory {
            return ShardAwareUuidFactory::fromConfig();
        });

        $this->app->singleton(UuidShardDecoder::class, function (Container $app): UuidShardDecoder {
            return new UuidShardDecoder(
                $app->make(ShardAwareUuidFactory::class),
                $app->make(ShardwiseManager::class)->getShards()
            );
        });
    }

    private function registerManager(): void
    {
        $this->app->singleton(ShardwiseManager::class, function (Container $app): ShardwiseManager {
            // Create with a deferred router that will be resolved later
            $manager = new ShardwiseManager(
                $app,
                new class implements ShardRouterInterface
                {
                    public function route(string|int $key): Contracts\ShardInterface
                    {
                        return app(ShardRouterInterface::class)->route($key);
                    }

                    public function routeForTable(string $table, string|int $key): Contracts\ShardInterface
                    {
                        return app(ShardRouterInterface::class)->routeForTable($table, $key);
                    }

                    public function getShardById(string $shardId): ?Contracts\ShardInterface
                    {
                        return app(ShardRouterInterface::class)->getShardById($shardId);
                    }

                    public function getShards(): ShardCollection
                    {
                        return app(ShardRouterInterface::class)->getShards();
                    }

                    public function getStrategy(): ShardStrategyInterface
                    {
                        return app(ShardRouterInterface::class)->getStrategy();
                    }

                    public function setStrategy(ShardStrategyInterface $strategy): void
                    {
                        app(ShardRouterInterface::class)->setStrategy($strategy);
                    }
                }
            );

            return $manager;
        });

        $this->app->singleton(Shardwise::class, function (Container $app): Shardwise {
            return new Shardwise($app->make(ShardwiseManager::class));
        });

        // Alias for the helper function
        $this->app->alias(Shardwise::class, 'shardwise');
    }

    private function registerHealth(): void
    {
        $this->app->singleton(ShardHealthChecker::class, function (Container $app): ShardHealthChecker {
            return new ShardHealthChecker(
                $app->make(DatabaseManager::class),
                $app->make(ShardConnectionFactory::class)
            );
        });

        // Register metrics singleton
        $this->app->singleton(ShardMetrics::class, function (): ShardMetrics {
            return ShardMetrics::getInstance();
        });
    }

    private function registerMigrations(): void
    {
        $this->app->singleton(ShardMigrator::class, function (Container $app): ShardMigrator {
            return new ShardMigrator(
                $app->make(ShardwiseManager::class),
                $app->make(\Illuminate\Contracts\Console\Kernel::class)
            );
        });

        $this->app->singleton(ShardMigrationRepository::class, function (Container $app): ShardMigrationRepository {
            /** @var \Illuminate\Database\ConnectionResolverInterface $db */
            $db = $app->make('db');

            /** @var \Illuminate\Filesystem\Filesystem $files */
            $files = $app->make('files');

            return new ShardMigrationRepository(
                $app->make(ShardwiseManager::class),
                $db,
                $files
            );
        });
    }

    private function bootShards(): void
    {
        /** @var array<string, array<string, mixed>> $shardsConfig */
        $shardsConfig = config('shardwise.shards', []);

        if ($shardsConfig !== []) {
            $shards = ShardCollection::fromConfig($shardsConfig);
            $this->app->make(ShardwiseManager::class)->setShards($shards);
        }
    }

    private function registerMacros(): void
    {
        QueryBuilderMacros::register();
    }

    private function registerQueueIntegration(): void
    {
        /** @var bool $queueAware */
        $queueAware = config('shardwise.queue.aware', true);

        if (! $queueAware) {
            return;
        }

        /** @var string $payloadKey */
        $payloadKey = config('shardwise.queue.payload_key', 'shardwise_shard_id');

        // Inject shard ID into queue job payloads
        Queue::createPayloadUsing(function () use ($payloadKey): array {
            $shard = ShardContext::current();

            if ($shard === null) {
                return [];
            }

            return [
                $payloadKey => $shard->getId(),
            ];
        });

        // Initialize shard context when job starts processing
        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($payloadKey): void {
            $payload = $event->job->payload();
            $shardId = $payload[$payloadKey] ?? null;

            if (is_string($shardId)) {
                shardwise()->initialize($shardId);
            }
        });

        // Clean up shard context when job finishes (success or failure)
        Event::listen(JobProcessed::class, function (JobProcessed $event) use ($payloadKey): void {
            $payload = $event->job->payload();
            $shardId = $payload[$payloadKey] ?? null;

            if (is_string($shardId) && ShardContext::active()) {
                shardwise()->end();
            }
        });

        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($payloadKey): void {
            $payload = $event->job->payload();
            $shardId = $payload[$payloadKey] ?? null;

            if (is_string($shardId) && ShardContext::active()) {
                shardwise()->end();
            }
        });
    }

    /**
     * Register Octane/Swoole/RoadRunner compatibility listeners.
     *
     * Flushes all static mutable state between requests to prevent
     * cross-request data leaks in long-running processes.
     */
    private function registerOctaneCompatibility(): void
    {
        $flush = function (): void {
            ShardContext::clear();
            ShardContext::flushTransactionState();
            ShardMetrics::flush();
            CrossShardBuilder::flushScatterState();
        };

        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $this->app['events']->listen(\Laravel\Octane\Events\RequestTerminated::class, $flush);
        }

        if (class_exists(\Laravel\Octane\Events\TaskTerminated::class)) {
            $this->app['events']->listen(\Laravel\Octane\Events\TaskTerminated::class, $flush);
        }
    }
}

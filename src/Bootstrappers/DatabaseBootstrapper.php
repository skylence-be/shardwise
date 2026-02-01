<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Bootstrappers;

use Illuminate\Database\DatabaseManager;
use Skylence\Shardwise\Connections\ShardConnectionFactory;
use Skylence\Shardwise\Contracts\BootstrapperInterface;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Bootstrapper that switches the database connection to the shard.
 */
final class DatabaseBootstrapper implements BootstrapperInterface
{
    private ?string $previousConnection = null;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ShardConnectionFactory $connectionFactory,
    ) {}

    /**
     * Bootstrap the shard context by switching the database connection.
     */
    public function bootstrap(ShardInterface $shard): void
    {
        $this->previousConnection = $this->database->getDefaultConnection();

        // Configure and get the shard connection
        $this->connectionFactory->configureConnection($shard);

        // Set as the default connection
        $this->database->setDefaultConnection($shard->getConnectionName());
    }

    /**
     * Revert to the previous database connection.
     */
    public function revert(): void
    {
        if ($this->previousConnection !== null) {
            $this->database->setDefaultConnection($this->previousConnection);
            $this->previousConnection = null;
        }
    }
}

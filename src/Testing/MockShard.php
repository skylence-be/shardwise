<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Testing;

use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Mock shard implementation for testing.
 */
final readonly class MockShard implements ShardInterface
{
    /**
     * @param  array<string, mixed>  $connectionConfig
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private string $id,
        private string $name,
        private string $connectionName,
        private array $connectionConfig = [],
        private int $weight = 1,
        private bool $active = true,
        private bool $readOnly = false,
        private array $metadata = [],
    ) {}

    /**
     * Create a simple mock shard for testing.
     */
    public static function create(string $id, ?string $name = null): self
    {
        return new self(
            id: $id,
            name: $name ?? "Mock Shard {$id}",
            connectionName: "mock_shard_{$id}",
            connectionConfig: [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        );
    }

    /**
     * Create a mock shard with custom configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $id, array $config): self
    {
        /** @var string $name */
        $name = $config['name'] ?? "Mock Shard {$id}";

        /** @var string $connection */
        $connection = $config['connection'] ?? "mock_shard_{$id}";

        /** @var array<string, mixed> $database */
        $database = $config['database'] ?? ['driver' => 'sqlite', 'database' => ':memory:'];

        /** @var int $weight */
        $weight = $config['weight'] ?? 1;

        /** @var bool $active */
        $active = $config['active'] ?? true;

        /** @var bool $readOnly */
        $readOnly = $config['read_only'] ?? false;

        /** @var array<string, mixed> $metadata */
        $metadata = $config['metadata'] ?? [];

        return new self(
            id: $id,
            name: $name,
            connectionName: $connection,
            connectionConfig: $database,
            weight: $weight,
            active: $active,
            readOnly: $readOnly,
            metadata: $metadata,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConnectionConfig(): array
    {
        return $this->connectionConfig;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

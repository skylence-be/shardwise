<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Immutable shard entity representing a single database shard.
 */
final readonly class Shard implements ShardInterface
{
    /**
     * @param  array<string, mixed>  $connectionConfig
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private string $id,
        private string $name,
        private string $connectionName,
        private array $connectionConfig,
        private int $weight = 1,
        private bool $active = true,
        private bool $readOnly = false,
        private array $metadata = [],
    ) {}

    /**
     * Create a shard from configuration array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $id, array $config): self
    {
        /** @var string $name */
        $name = $config['name'] ?? $id;

        /** @var string $connection */
        $connection = $config['connection'] ?? "shardwise_{$id}";

        /** @var array<string, mixed> $database */
        $database = $config['database'] ?? [];

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

    /**
     * Create a new shard with different active state.
     */
    public function withActive(bool $active): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            connectionName: $this->connectionName,
            connectionConfig: $this->connectionConfig,
            weight: $this->weight,
            active: $active,
            readOnly: $this->readOnly,
            metadata: $this->metadata,
        );
    }

    /**
     * Create a new shard with different read-only state.
     */
    public function withReadOnly(bool $readOnly): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            connectionName: $this->connectionName,
            connectionConfig: $this->connectionConfig,
            weight: $this->weight,
            active: $this->active,
            readOnly: $readOnly,
            metadata: $this->metadata,
        );
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Health;

use DateTimeImmutable;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Represents the result of a shard health check.
 */
final readonly class HealthCheckResult
{
    private function __construct(
        private ShardInterface $shard,
        private bool $healthy,
        private ?string $error,
        private ?int $latencyMs,
        private DateTimeImmutable $checkedAt,
    ) {}

    /**
     * Create a healthy result.
     */
    public static function healthy(ShardInterface $shard, ?int $latencyMs = null): self
    {
        return new self(
            shard: $shard,
            healthy: true,
            error: null,
            latencyMs: $latencyMs,
            checkedAt: new DateTimeImmutable,
        );
    }

    /**
     * Create an unhealthy result.
     */
    public static function unhealthy(ShardInterface $shard, string $error, ?int $latencyMs = null): self
    {
        return new self(
            shard: $shard,
            healthy: false,
            error: $error,
            latencyMs: $latencyMs,
            checkedAt: new DateTimeImmutable,
        );
    }

    /**
     * Get the shard.
     */
    public function getShard(): ShardInterface
    {
        return $this->shard;
    }

    /**
     * Check if the shard is healthy.
     */
    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    /**
     * Get the error message (if unhealthy).
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get the latency in milliseconds.
     */
    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    /**
     * Get the timestamp when the check was performed.
     */
    public function getCheckedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shard_id' => $this->shard->getId(),
            'healthy' => $this->healthy,
            'error' => $this->error,
            'latency_ms' => $this->latencyMs,
            'checked_at' => $this->checkedAt->format('Y-m-d H:i:s'),
        ];
    }
}

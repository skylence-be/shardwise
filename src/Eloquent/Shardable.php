<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Skylence\Shardwise\Contracts\ShardInterface;
use Skylence\Shardwise\ShardContext;
use Skylence\Shardwise\Uuid\ShardAwareUuidFactory;
use Skylence\Shardwise\Uuid\UuidShardDecoder;

/**
 * Trait for making Eloquent models shard-aware.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait Shardable
{
    /**
     * Boot the shardable trait.
     */
    public static function bootShardable(): void
    {
        // Auto-generate shard-aware UUID on creating if model uses UUIDs
        static::creating(function ($model): void {
            if ($model->usesShardAwareUuid() && empty($model->{$model->getKeyName()})) {
                /** @var ShardAwareUuidFactory $factory */
                $factory = app(ShardAwareUuidFactory::class);
                $model->{$model->getKeyName()} = $factory->generateString();
            }
        });
    }

    /**
     * Query on a specific shard.
     *
     * @return ShardableBuilder<static>
     */
    public static function onShard(ShardInterface|string $shard): ShardableBuilder
    {
        return static::query()->onShard($shard);
    }

    /**
     * Query on all shards.
     *
     * @return ShardableBuilder<static>
     */
    public static function onAllShards(): ShardableBuilder
    {
        return static::query()->onAllShards();
    }

    /**
     * Query using the central connection.
     *
     * @return Builder<static>
     */
    public static function onCentral(): Builder
    {
        /** @var string $centralConnection */
        $centralConnection = config('shardwise.central_connection', 'mysql');

        return static::on($centralConnection);
    }

    /**
     * Get a new query builder instance for a shard-aware query.
     */
    public function newEloquentBuilder($query): ShardableBuilder
    {
        return new ShardableBuilder($query);
    }

    /**
     * Get the column name used as the shard key.
     */
    public function getShardKeyColumn(): string
    {
        /** @var string */
        return $this->shardKeyColumn ?? $this->getKeyName();
    }

    /**
     * Get the shard key value for this model instance.
     */
    public function getShardKeyValue(): string|int|null
    {
        $column = $this->getShardKeyColumn();

        return $this->getAttribute($column);
    }

    /**
     * Get the table group this model belongs to.
     */
    public function getTableGroup(): ?string
    {
        return $this->tableGroup ?? null;
    }

    /**
     * Determine the shard for this model instance.
     */
    public function resolveShard(): ?ShardInterface
    {
        $shardKey = $this->getShardKeyValue();

        if ($shardKey === null) {
            return ShardContext::current();
        }

        // If the shard key looks like a UUID, try to extract shard from it
        if (is_string($shardKey) && $this->looksLikeUuid($shardKey)) {
            /** @var UuidShardDecoder $decoder */
            $decoder = app(UuidShardDecoder::class);
            $shard = $decoder->decode($shardKey);

            if ($shard !== null) {
                return $shard;
            }
        }

        // Fall back to routing via the manager
        return shardwise()->route($shardKey);
    }

    /**
     * Check if this model uses shard-aware UUIDs.
     */
    public function usesShardAwareUuid(): bool
    {
        return $this->shardAwareUuid ?? false;
    }

    /**
     * Get the connection for this model, considering shard context.
     */
    public function getConnectionName(): ?string
    {
        // If explicitly set, use it
        if ($this->connection !== null) {
            return $this->connection;
        }

        // If in shard context, use the shard connection
        $shard = ShardContext::current();
        if ($shard !== null) {
            return $shard->getConnectionName();
        }

        return null;
    }

    /**
     * Check if a string looks like a UUID.
     */
    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}

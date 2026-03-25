<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Macros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Skylence\Shardwise\Contracts\ShardInterface;

/**
 * Registers macros on Laravel's query builders for shard operations.
 */
final class QueryBuilderMacros
{
    /**
     * Register all macros.
     */
    public static function register(): void
    {
        self::registerEloquentMacros();
        self::registerQueryMacros();
    }

    /**
     * Register macros on the Eloquent builder.
     */
    private static function registerEloquentMacros(): void
    {
        /**
         * Execute the query on a specific shard.
         */
        Builder::macro('onShard', function (ShardInterface|string $shard): Builder {
            if (is_string($shard)) {
                $shard = shardwise()->getShard($shard);
            }

            /** @var Builder $this */
            $this->getQuery()->connection = app('db')->connection($shard->getConnectionName());

            return $this;
        });

        /**
         * Execute the query on all shards and merge results immediately.
         */
        Builder::macro('getFromAllShards', function (): Collection {
            /** @var Builder $this */
            $results = new Collection;

            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                $shardResults = shardwise()->run($shard, fn (): Collection => $this->get());

                $results = $results->merge($shardResults);
            }

            return $results;
        });

        /**
         * Get the count from all shards.
         */
        Builder::macro('countOnAllShards', function (string $columns = '*'): int {
            /** @var Builder $this */
            $total = 0;

            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                $total += shardwise()->run($shard, fn (): int => $this->count($columns));
            }

            return $total;
        });

        /**
         * Check if records exist on any shard.
         */
        Builder::macro('existsOnAnyShards', function (): bool {
            /** @var Builder $this */
            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                $exists = shardwise()->run($shard, fn (): bool => $this->exists());

                if ($exists) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Register macros on the base query builder.
     */
    private static function registerQueryMacros(): void
    {
        /**
         * Execute the query on a specific shard.
         */
        QueryBuilder::macro('onShard', function (ShardInterface|string $shard): QueryBuilder {
            if (is_string($shard)) {
                $shard = shardwise()->getShard($shard);
            }

            /** @var QueryBuilder $this */
            $this->connection = app('db')->connection($shard->getConnectionName());

            return $this;
        });

        /**
         * Execute the query on all shards and merge results immediately.
         */
        QueryBuilder::macro('getFromAllShards', function (): \Illuminate\Support\Collection {
            /** @var QueryBuilder $this */
            $results = collect();

            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                $shardResults = shardwise()->run($shard, fn (): \Illuminate\Support\Collection => $this->get());

                $results = $results->merge($shardResults);
            }

            return $results;
        });

        /**
         * Get the count from all shards.
         */
        QueryBuilder::macro('countOnAllShards', function (string $columns = '*'): int {
            /** @var QueryBuilder $this */
            $total = 0;

            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                $total += shardwise()->run($shard, fn (): int => $this->count($columns));
            }

            return $total;
        });

        /**
         * Get the sum from all shards.
         */
        QueryBuilder::macro('sumOnAllShards', function (string $column): int|float {
            /** @var QueryBuilder $this */
            $total = 0;

            $shards = shardwise()->getShards()->active();

            foreach ($shards as $shard) {
                $total += shardwise()->run($shard, fn (): int|float => $this->sum($column));
            }

            return $total;
        });
    }
}

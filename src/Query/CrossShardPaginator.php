<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

/**
 * Paginator for cross-shard queries.
 *
 * @template TModel of Model
 */
final class CrossShardPaginator
{
    /**
     * @param  Builder<TModel>  $builder
     */
    public function __construct(
        private readonly Builder $builder,
    ) {}

    /**
     * Paginate results from all shards.
     *
     * Note: This is an approximation since we can't know exact offsets
     * per shard. For accurate pagination with large datasets, consider
     * using cursor-based pagination or keyset pagination.
     *
     * @param  array<int, string>  $columns
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        ?string $orderByColumn = null,
        string $orderDirection = 'asc',
    ): LengthAwarePaginator {
        $page ??= LengthAwarePaginator::resolveCurrentPage($pageName);

        // Get total count from all shards
        $crossShardBuilder = new CrossShardBuilder($this->builder);
        $total = $crossShardBuilder->count();

        // Calculate the offset for this page
        $offset = ($page - 1) * $perPage;

        // We need to fetch at least offset + perPage items to cover the requested page
        // Each shard should fetch this amount to handle uneven distribution
        $itemsToFetch = $offset + $perPage;

        // Get items from all shards
        $allItems = $this->fetchFromAllShards($columns, $itemsToFetch, $orderByColumn, $orderDirection);

        // Sort the combined results if order is specified
        if ($orderByColumn !== null) {
            $allItems = $this->sortResults($allItems, $orderByColumn, $orderDirection);
        }

        // Apply offset and limit
        $items = $allItems->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    /**
     * Cursor-based pagination across shards.
     *
     * More efficient for large datasets as it doesn't require counting.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    public function cursorPaginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $cursorColumn = 'id',
        string $direction = 'asc',
        mixed $cursor = null,
    ): Collection {
        $allItems = new Collection;

        $shards = shardwise()->getShards()->active();

        foreach ($shards as $shard) {
            $shardItems = shardwise()->run($shard, function () use ($perPage, $columns, $cursorColumn, $direction, $cursor): Collection {
                $query = clone $this->builder;

                if ($cursor !== null) {
                    $operator = $direction === 'asc' ? '>' : '<';
                    $query->where($cursorColumn, $operator, $cursor);
                }

                return $query
                    ->orderBy($cursorColumn, $direction)
                    ->take($perPage)
                    ->get($columns);
            });

            $allItems = $allItems->merge($shardItems);
        }

        // Sort and limit
        $sorted = $direction === 'asc'
            ? $allItems->sortBy($cursorColumn)
            : $allItems->sortByDesc($cursorColumn);

        return $sorted->take($perPage)->values();
    }

    /**
     * Fetch results from all shards.
     *
     * Each shard fetches the full limit because data distribution may be uneven.
     * For example, if all data is on one shard, we need that shard to return enough items.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    private function fetchFromAllShards(
        array $columns,
        int $limit,
        ?string $orderByColumn,
        string $orderDirection,
    ): Collection {
        $shards = shardwise()->getShards()->active();
        $useAmphp = config('shardwise.parallel_queries.enabled', false)
            && config('shardwise.parallel_queries.driver', 'amphp') === 'amphp'
            && $shards->count() > 1
            && class_exists(\Amp\Postgres\PostgresConnectionPool::class);

        if ($useAmphp) {
            return $this->fetchFromAllShardsAmphp($shards, $columns, $limit, $orderByColumn, $orderDirection);
        }

        return $this->fetchFromAllShardsSequential($shards, $columns, $limit, $orderByColumn, $orderDirection);
    }

    /**
     * Fetch from all shards sequentially.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    private function fetchFromAllShardsSequential(
        \Skylence\Shardwise\ShardCollection $shards,
        array $columns,
        int $limit,
        ?string $orderByColumn,
        string $orderDirection,
    ): Collection {
        $allItems = new Collection;

        foreach ($shards as $shard) {
            $shardItems = shardwise()->run($shard, function () use ($shard, $columns, $limit, $orderByColumn, $orderDirection): Collection {
                $model = $this->builder->getModel();
                $query = $model->on($shard->getConnectionName())->newQuery();
                $query->getQuery()->wheres = $this->builder->getQuery()->wheres;
                $query->getQuery()->bindings = $this->builder->getQuery()->bindings;

                if ($orderByColumn !== null) {
                    $query->orderBy($orderByColumn, $orderDirection);
                }

                return $query->take($limit)->get($columns);
            });

            $allItems = $allItems->merge($shardItems);
        }

        return $allItems;
    }

    /**
     * Fetch from all shards concurrently using AmPHP Fibers.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    private function fetchFromAllShardsAmphp(
        \Skylence\Shardwise\ShardCollection $shards,
        array $columns,
        int $limit,
        ?string $orderByColumn,
        string $orderDirection,
    ): Collection {
        // Build SQL from builder
        $model = $this->builder->getModel();
        $fresh = $model->newQuery();
        $fresh->getQuery()->wheres = $this->builder->getQuery()->wheres;
        $fresh->getQuery()->bindings = $this->builder->getQuery()->bindings;

        if ($orderByColumn !== null) {
            $fresh->orderBy($orderByColumn, $orderDirection);
        }
        $fresh->take($limit);
        $fresh->select($columns);

        $sql = $fresh->toSql();
        $bindings = $fresh->getBindings();
        $tolerateDeadShards = config('shardwise.dead_shard_tolerance', false);

        try {
            $shardResults = \Skylence\Shardwise\Async\AsyncShardQueryExecutor::queryAll(
                $shards, $sql, $bindings, $tolerateDeadShards
            );

            $allItems = new Collection;
            foreach ($shardResults as $rows) {
                foreach ($rows as $row) {
                    $allItems->push($model->newFromBuilder((object) $row));
                }
            }

            return $allItems;
        } catch (Throwable) {
            return $this->fetchFromAllShardsSequential($shards, $columns, $limit, $orderByColumn, $orderDirection);
        }
    }

    /**
     * Sort the combined results.
     *
     * @param  Collection<int, TModel>  $items
     * @return Collection<int, TModel>
     */
    private function sortResults(Collection $items, string $column, string $direction): Collection
    {
        return $direction === 'asc'
            ? $items->sortBy($column)
            : $items->sortByDesc($column);
    }
}

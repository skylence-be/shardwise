<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Queue;

use Closure;
use Skylence\Shardwise\ShardContext;

/**
 * Queue middleware that initializes shard context for jobs.
 *
 * Add this middleware to jobs that need to run in a shard context:
 *
 * ```php
 * public function middleware(): array
 * {
 *     return [new ShardwiseJobMiddleware($this->shardId)];
 * }
 * ```
 */
final class ShardwiseJobMiddleware
{
    public function __construct(
        private readonly ?string $shardId = null,
    ) {}

    /**
     * Handle the job.
     *
     * @param  object  $job
     * @param  Closure(object): mixed  $next
     */
    public function handle(mixed $job, Closure $next): mixed
    {
        $shardId = $this->resolveShardId($job);

        if ($shardId === null) {
            return $next($job);
        }

        return shardwise()->run($shardId, fn (): mixed => $next($job));
    }

    /**
     * Resolve the shard ID from the job or middleware configuration.
     */
    private function resolveShardId(mixed $job): ?string
    {
        // First check middleware configuration
        if ($this->shardId !== null) {
            return $this->shardId;
        }

        // Then check if job has ShardAwareJob trait
        if (is_object($job) && method_exists($job, 'getShardId')) {
            /** @var mixed $shardId */
            $shardId = $job->getShardId();

            return is_string($shardId) ? $shardId : null;
        }

        // Check for shardId property
        if (is_object($job) && property_exists($job, 'shardId')) {
            /** @var mixed $shardId */
            $shardId = $job->shardId;

            return is_string($shardId) ? $shardId : null;
        }

        // Check current context
        return ShardContext::currentId();
    }
}

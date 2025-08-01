<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Skylence\Shardwise\Shardwise
 */
final class Shardwise extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Skylence\Shardwise\Shardwise::class;
    }
}

<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Illuminate\Console\Command;

final class ShardwiseCommand extends Command
{
    public $signature = 'sharded-db-package';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}

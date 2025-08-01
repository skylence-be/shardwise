<?php

declare(strict_types=1);

namespace Skylence\Shardwise;

use Skylence\Shardwise\Commands\ShardwiseCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ShardwiseServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('sharded-db-package')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_sharded_db_package_table')
            ->hasCommand(ShardwiseCommand::class);
    }
}

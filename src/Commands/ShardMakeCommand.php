<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

final class ShardMakeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'shardwise:make-migration
        {name? : The name of the migration}
        {--create= : The table to be created}
        {--table= : The table to migrate}';

    /**
     * @var string
     */
    protected $description = 'Create a new shard migration file';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name') ?? text('Migration name:', required: true);

        $table = $this->option('table');
        $create = $this->option('create') ?: false;

        if (! $table && is_string($create)) {
            $table = $create;
            $create = true;
        }

        $path = $this->getMigrationPath();

        $this->ensureDirectoryExists($path);

        $file = $this->createMigration($name, $path, $table, $create);

        info("Created migration: {$file}");

        return self::SUCCESS;
    }

    /**
     * Get the migration path.
     */
    private function getMigrationPath(): string
    {
        /** @var string */
        return config('shardwise.migrations.path', database_path('migrations/shards'));
    }

    /**
     * Ensure the directory exists.
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    /**
     * Create the migration file.
     */
    private function createMigration(string $name, string $path, ?string $table, bool $create): string
    {
        $className = $this->getClassName($name);
        $fileName = $this->getFileName($name);
        $filePath = "{$path}/{$fileName}.php";

        $stub = $this->getStub($table, $create);
        $content = $this->populateStub($stub, $className, $table);

        $this->files->put($filePath, $content);

        return $fileName;
    }

    /**
     * Get the class name for the migration.
     */
    private function getClassName(string $name): string
    {
        return str($name)->studly()->toString();
    }

    /**
     * Get the file name for the migration.
     */
    private function getFileName(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $snakeName = str($name)->snake()->toString();

        return "{$timestamp}_{$snakeName}";
    }

    /**
     * Get the migration stub.
     */
    private function getStub(?string $table, bool $create): string
    {
        if ($table === null) {
            return $this->getBlankStub();
        }

        if ($create) {
            return $this->getCreateStub();
        }

        return $this->getUpdateStub();
    }

    /**
     * Get a blank migration stub.
     */
    private function getBlankStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
STUB;
    }

    /**
     * Get a create table migration stub.
     */
    private function getCreateStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{ table }}', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{ table }}');
    }
};
STUB;
    }

    /**
     * Get an update table migration stub.
     */
    private function getUpdateStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{{ table }}', function (Blueprint $table): void {
            //
        });
    }

    public function down(): void
    {
        Schema::table('{{ table }}', function (Blueprint $table): void {
            //
        });
    }
};
STUB;
    }

    /**
     * Populate the stub with values.
     */
    private function populateStub(string $stub, string $className, ?string $table): string
    {
        $stub = str_replace('{{ class }}', $className, $stub);
        $stub = str_replace('{{ table }}', $table ?? 'table_name', $stub);

        return $stub;
    }
}

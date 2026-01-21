<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Random\RandomException;
use W33bvgl\MoonShineChunkUpload\Providers\ChunkUploadServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
        $moonshineMigrations = realpath(__DIR__ . '/../vendor/moonshine/moonshine/src/Laravel/database/migrations');

        if ($moonshineMigrations) {
            $this->loadMigrationsFrom($moonshineMigrations);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            ChunkUploadServiceProvider::class,
            TestingServiceProvider::class,
        ];
    }

    /**
     * @throws RandomException
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
        ]);

        $app['config']->set('chunk-upload.storage.chunks', 'local/chunks');

        $app['config']->set('moonshine.use_migrations', true);
        $app['config']->set('moonshine.use_auth', true);
    }
}
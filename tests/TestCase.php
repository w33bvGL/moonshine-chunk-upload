<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Tests;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Random\RandomException;
use Illuminate\Foundation\Testing\RefreshDatabase;


#[WithMigration]
abstract class TestCase extends Orchestra
{
    use WithWorkbench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @throws RandomException
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');

        $storagePath = __DIR__ . '/storage';

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => $storagePath,
        ]);

        $app['config']->set('chunk-upload.storage.chunks', 'local/chunks');

        $app['config']->set('moonshine.use_migrations', true);
        $app['config']->set('moonshine.use_auth', false);
    }

    protected function defineRoutes($router): void
    {
        $router->getRoutes()->refreshNameLookups();
        $router->setRoutes(new RouteCollection);

        $router->get('/playground', fn() => 'Only me!');
    }
}
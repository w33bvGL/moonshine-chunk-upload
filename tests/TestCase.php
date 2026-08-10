<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use W33bvgl\MoonShineChunkUpload\Providers\MoonshineChunkUploadServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->storagePath());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storagePath());

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MoonshineChunkUploadServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => $this->storagePath(),
        ]);

        $app['config']->set('moonshine-chunk-upload.disk', 'local');
        $app['config']->set('moonshine-chunk-upload.tmp_dir', 'tmp');
        $app['config']->set('moonshine-chunk-upload.final_dir', 'final');
    }

    protected function storagePath(): string
    {
        return __DIR__.'/storage/app';
    }
}

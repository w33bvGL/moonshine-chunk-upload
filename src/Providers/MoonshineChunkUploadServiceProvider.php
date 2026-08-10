<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use W33bvgl\MoonShineChunkUpload\Console\Commands\PruneChunkUploadsCommand;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadConfig;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

final class MoonshineChunkUploadServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/moonshine-chunk-upload.php',
            'moonshine-chunk-upload',
        );

        $this->app->singleton(
            ChunkUploadConfig::class,
            static fn ($app): ChunkUploadConfig => ChunkUploadConfig::fromRepository($app->make(Repository::class)),
        );

        $this->app->singleton(ChunkUploadManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'moonshine-chunk-upload');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'moonshine-chunk-upload');
        $this->loadRoutesFrom(__DIR__.'/../../routes/chunk-upload.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PruneChunkUploadsCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../../config/moonshine-chunk-upload.php' => config_path('moonshine-chunk-upload.php'),
        ], 'moonshine-chunk-upload-config');

        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/moonshine-chunk-upload'),
        ], 'moonshine-chunk-upload-views');

        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/moonshine-chunk-upload'),
        ], 'moonshine-chunk-upload-lang');

        $this->publishes([
            __DIR__.'/../../public' => public_path('vendor/moonshine-chunk-upload'),
        ], ['moonshine-chunk-upload-assets', 'laravel-assets']);
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Providers;

use Illuminate\Support\ServiceProvider;
use W33bvgl\MoonShineChunkUpload\Console\Commands\PruneChunkUploadsCommand;

final class MoonshineChunkUploadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/moonshine-chunk-upload.php',
            'moonshine-chunk-upload',
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'moonshine-chunk-upload');
        $this->loadRoutesFrom(__DIR__.'/../../routes/chunk-upload.php');

        if ($this->app->runningInConsole()) {
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
                __DIR__.'/../../dist' => public_path('vendor/moonshine-chunk-upload'),
            ], ['moonshine-chunk-upload-assets', 'laravel-assets']);
        }
    }
}

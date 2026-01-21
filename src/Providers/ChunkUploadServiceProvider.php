<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Providers;

use Illuminate\Support\ServiceProvider;

final class ChunkUploadServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'moonshine-chunk-upload');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/moonshine-chunk-upload.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../resources/views' => resource_path('views/vendor/moonshine-chunk-upload'),
            ], 'moonshine-chunk-upload-views');
        }
    }
}
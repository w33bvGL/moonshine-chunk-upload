<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use W33bvgl\MoonShineChunkUpload\Console\Commands\PruneChunkUploadsCommand;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadConfig;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

final class MoonShineChunkUploadServiceProvider extends ServiceProvider
{
    private const string PACKAGE = 'moonshine-chunk-upload';

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('config/moonshine-chunk-upload.php'), self::PACKAGE);

        $this->app->singleton(
            ChunkUploadConfig::class,
            static fn (Application $app): ChunkUploadConfig => ChunkUploadConfig::fromRepository(
                $app->make(Repository::class),
            ),
        );

        $this->app->singleton(ChunkUploadManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom($this->packagePath('resources/views'), self::PACKAGE);
        $this->loadTranslationsFrom($this->packagePath('lang'), self::PACKAGE);
        $this->loadRoutesFrom($this->packagePath('routes/chunk-upload.php'));

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PruneChunkUploadsCommand::class,
        ]);

        $this->publishes([
            $this->packagePath('config/moonshine-chunk-upload.php') => config_path('moonshine-chunk-upload.php'),
        ], self::PACKAGE.'-config');

        $this->publishes([
            $this->packagePath('resources/views') => resource_path('views/vendor/'.self::PACKAGE),
        ], self::PACKAGE.'-views');

        $this->publishes([
            $this->packagePath('lang') => lang_path('vendor/'.self::PACKAGE),
        ], self::PACKAGE.'-lang');

        $this->publishes([
            $this->packagePath('public') => public_path('vendor/'.self::PACKAGE),
        ], [self::PACKAGE.'-assets', 'laravel-assets']);
    }

    private function packagePath(string $path): string
    {
        return \dirname(__DIR__, 2).'/'.$path;
    }
}

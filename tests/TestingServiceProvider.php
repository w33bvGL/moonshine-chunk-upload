<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Tests;

use Illuminate\Support\ServiceProvider;
use MoonShine\Contracts\Core\DependencyInjection\ConfiguratorContract;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Laravel\Resources\MoonShineUserResource;
use MoonShine\Laravel\Resources\MoonShineUserRoleResource;
use W33bvgl\MoonShineChunkUpload\Tests\MoonShine\Resources\ChunkTestResource;

final class TestingServiceProvider extends ServiceProvider
{
    public function boot(CoreContract $core, ConfiguratorContract $config): void
    {
        $core->resources([
            MoonShineUserResource::class,
            MoonShineUserRoleResource::class,
            ChunkTestResource::class,
        ])->pages($config->getPages());
    }
}

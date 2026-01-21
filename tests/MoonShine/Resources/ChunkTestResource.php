<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Tests\MoonShine\Resources;

use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\UI\Components\Layout\Box;
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

class ChunkTestResource extends ModelResource
{
    protected string $title = 'Chunk Upload Test';

    protected string $model = MoonShineUser::class;

    public function fields(): array
    {
        return [
            Box::make([
                ChunkUpload::make('Video File', 'video_path')
                    ->disk('local')
                    ->required(),
            ])
        ];
    }
}
<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Events;

use W33bvgl\MoonShineChunkUpload\Support\UploadMeta;

final readonly class ChunkUploadCompleted
{
    public function __construct(
        public string $uploadId,
        public string $path,
        public UploadMeta $meta,
    ) {}
}

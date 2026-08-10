<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Events;

use W33bvgl\MoonShineChunkUpload\Support\UploadMeta;

/**
 * Fired once the parts have been assembled into a single file, before any field
 * claims it — the hook for transcoding, virus scanning or queueing follow-up work.
 *
 * The path is relative to the upload disk (`moonshine-chunk-upload.disk`) and
 * still lives in the staging directory at this point.
 */
final readonly class ChunkUploadCompleted
{
    public function __construct(
        public string $uploadId,
        public string $path,
        public UploadMeta $meta,
    ) {}
}

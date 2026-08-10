<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

use Illuminate\Support\Facades\Storage;
use W33bvgl\MoonShineChunkUpload\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Backdates a file or directory on the upload disk, so TTL-based pruning has
 * something to find without the test having to wait for it.
 */
function ageOf(string $relative, int $hours): void
{
    touch(Storage::disk('local')->path($relative), time() - $hours * 3600);
}

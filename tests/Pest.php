<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use Illuminate\Support\Facades\Storage;
use W33bvgl\MoonShineChunkUpload\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function ageOf(string $relative, int $hours): void
{
    touch(Storage::disk('local')->path($relative), time() - $hours * 3600);
}

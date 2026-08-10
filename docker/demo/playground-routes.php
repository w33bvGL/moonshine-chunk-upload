<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use Illuminate\Support\Facades\Route;
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

Route::post('/playground/apply', function () {
    $item = ['video' => null, 'audio' => null, 'subtitle' => null];

    $fields = [
        ChunkUpload::make('Video', 'video')->disk('public')->dir('videos'),
        ChunkUpload::make('Audio', 'audio')->disk('public')->dir('audio')->keepOriginalFileName(),
        ChunkUpload::make('Subtitle', 'subtitle')->disk('public')->dir('subtitles'),
    ];

    foreach ($fields as $field) {
        $item = $field->apply(static fn (array $data): array => $data, $item);
    }

    return back()->with('playground.applied', $item);
})->name('playground.apply');

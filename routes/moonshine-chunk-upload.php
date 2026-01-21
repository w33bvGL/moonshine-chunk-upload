<?php

use Illuminate\Support\Facades\Route;
use W33bvgl\MoonShineChunkUpload\Http\Controllers\ChunkUploadController;

Route::post('/moonshine-chunk-upload', [ChunkUploadController::class, 'upload'])
    ->name('moonshine-chunk.upload')
    ->middleware(config('moonshine.route.middleware'));
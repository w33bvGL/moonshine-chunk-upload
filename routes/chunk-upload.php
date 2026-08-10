<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

use Illuminate\Support\Facades\Route;
use W33bvgl\MoonShineChunkUpload\Http\Controllers\ChunkUploadController;

Route::prefix(config('moonshine-chunk-upload.route.prefix'))
    ->middleware(config('moonshine-chunk-upload.route.middleware'))
    ->name(config('moonshine-chunk-upload.route.name'))
    ->controller(ChunkUploadController::class)
    ->group(function (): void {
        Route::post('init', 'init')->name('init');
        Route::post('chunk', 'chunk')->name('chunk');
        Route::get('status', 'status')->name('status');
        Route::post('finalize', 'finalize')->name('finalize');
        Route::delete('abort', 'abort')->name('abort');
    });

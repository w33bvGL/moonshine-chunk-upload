<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use Illuminate\Support\Facades\Route;
use W33bvgl\MoonShineChunkUpload\Http\Controllers\ChunkUploadController;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadConfig;

$config = resolve(ChunkUploadConfig::class);

Route::prefix($config->routePrefix)
    ->middleware($config->routeMiddleware)
    ->name($config->routeName)
    ->controller(ChunkUploadController::class)
    ->group(static function (): void {
        Route::post('init', 'init')->name('init');
        Route::post('chunk', 'chunk')->name('chunk');
        Route::get('status', 'status')->name('status');
        Route::post('finalize', 'finalize')->name('finalize');
        Route::delete('abort', 'abort')->name('abort');
    });

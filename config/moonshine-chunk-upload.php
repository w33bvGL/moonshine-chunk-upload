<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Chunked upload storage
    |--------------------------------------------------------------------------
    |
    | Every upload gets its own tmp directory (one file per chunk), which
    | makes parallel chunk requests race-free. Finalize assembles the parts
    | into the final directory the host application picks up from.
    |
    */

    'disk' => env('CHUNK_UPLOAD_DISK', 'local'),

    'tmp_dir' => 'chunked-uploads/tmp',

    'final_dir' => 'chunked-uploads/final',

    // Hard server-side cap per chunk request body.
    'max_chunk_size' => 16 * 1024 * 1024,

    // Hard server-side cap on the assembled file. Bounds both the declared size
    // and the chunk count, so one upload cannot be used to exhaust the disk.
    'max_file_size' => (int) env('CHUNK_UPLOAD_MAX_FILE_SIZE', 32 * 1024 * 1024 * 1024),

    // Stale tmp uploads (never finalized) are pruned after this many hours.
    'tmp_ttl_hours' => 24,

    // Finalized files never picked up by the host application are pruned after this many hours.
    'final_ttl_hours' => 72,

    /*
    |--------------------------------------------------------------------------
    | Extension profiles
    |--------------------------------------------------------------------------
    */

    'profiles' => [
        'video' => ['mp4', 'mov', 'mkv', 'webm', 'm4v'],
        'audio' => ['mp3', 'aac', 'wav', 'flac', 'm4a', 'ogg'],
        'subtitle' => ['srt', 'vtt', 'ass'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The host application controls auth/permission middleware here since
    | that's inherently app-specific (which admin section/ability guards
    | the upload endpoints).
    |
    */

    'route' => [
        'prefix' => 'moonshine-chunk-upload',
        'name' => 'moonshine-chunk-upload.',
        'middleware' => ['web'],
    ],

];

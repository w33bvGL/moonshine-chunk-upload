<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Chunked upload storage
    |--------------------------------------------------------------------------
    |
    | Every upload gets its own tmp directory (one file per chunk), which makes
    | parallel chunk requests race-free. Finalize assembles the parts into the
    | final directory, where the field picks the file up on form submit and
    | moves it onto its own disk.
    |
    | This disk is where assembly happens: it must be a local disk, since parts
    | are concatenated and renamed on the filesystem. The disk a field stores
    | its file on afterwards (ChunkUpload::disk()) can be anything.
    |
    */

    'disk' => env('CHUNK_UPLOAD_DISK', 'local'),

    'tmp_dir' => 'chunked-uploads/tmp',

    'final_dir' => 'chunked-uploads/final',

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | max_chunk_size is a hard server-side cap per chunk request body — keep it
    | under the web server's own body limit (nginx client_max_body_size, PHP
    | post_max_size). ChunkUpload::chunkSize() is clamped to it.
    |
    | max_file_size bounds both the declared size and the chunk count, so one
    | upload cannot be used to exhaust the disk.
    |
    */

    'max_chunk_size' => (int) env('CHUNK_UPLOAD_MAX_CHUNK_SIZE', 16 * 1024 * 1024),

    'max_file_size' => (int) env('CHUNK_UPLOAD_MAX_FILE_SIZE', 32 * 1024 * 1024 * 1024),

    // Stale tmp uploads (never finalized) are pruned after this many hours.
    'tmp_ttl_hours' => (int) env('CHUNK_UPLOAD_TMP_TTL_HOURS', 24),

    // Finalized files never claimed by a form submit are pruned after this many hours.
    'final_ttl_hours' => (int) env('CHUNK_UPLOAD_FINAL_TTL_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Extension profiles
    |--------------------------------------------------------------------------
    |
    | A field picks one profile; init refuses any filename whose extension is
    | not on that profile's list. ChunkUpload::allowedExtensions() narrows the
    | list further for a single field.
    |
    */

    'profiles' => [
        'video' => ['mp4', 'mov', 'mkv', 'webm', 'm4v'],
        'audio' => ['mp3', 'aac', 'wav', 'flac', 'm4a', 'ogg'],
        'subtitle' => ['srt', 'vtt', 'ass'],
        'archive' => ['zip', '7z', 'rar', 'tar', 'gz'],
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The endpoints are unauthenticated out of the box because only the host
    | application knows which guard protects its admin panel. Put your own
    | auth/permission middleware here — for a stock MoonShine install that is
    | usually:
    |
    |     'middleware' => ['web', MoonShine\Laravel\Http\Middleware\Authenticate::class],
    |
    | A throttle entry ('throttle:120,1') is worth adding on public-facing panels.
    |
    */

    'route' => [
        'prefix' => 'moonshine-chunk-upload',
        'name' => 'moonshine-chunk-upload.',
        'middleware' => ['web'],
    ],

];

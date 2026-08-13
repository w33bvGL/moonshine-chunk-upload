<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

return [

    'disk' => env('CHUNK_UPLOAD_DISK', 'local'),

    'tmp_dir' => 'chunked-uploads/tmp',

    'final_dir' => 'chunked-uploads/final',

    'max_chunk_size' => (int) env('CHUNK_UPLOAD_MAX_CHUNK_SIZE', 16 * 1024 * 1024),

    'max_file_size' => (int) env('CHUNK_UPLOAD_MAX_FILE_SIZE', 32 * 1024 * 1024 * 1024),

    'tmp_ttl_hours' => (int) env('CHUNK_UPLOAD_TMP_TTL_HOURS', 24),

    'final_ttl_hours' => (int) env('CHUNK_UPLOAD_FINAL_TTL_HOURS', 72),

    'profiles' => [
        'video' => ['mp4', 'mov', 'mkv', 'webm', 'm4v'],
        'audio' => ['mp3', 'aac', 'wav', 'flac', 'm4a', 'ogg'],
        'subtitle' => ['srt', 'vtt', 'ass'],
        'archive' => ['zip', '7z', 'rar', 'tar', 'gz'],
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
    ],

    'route' => [
        'prefix' => 'moonshine-chunk-upload',
        'name' => 'moonshine-chunk-upload.',
        'middleware' => ['web'],
    ],

];

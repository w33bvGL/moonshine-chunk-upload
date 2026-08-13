<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

it('renders the hidden input under the field name', function (): void {
    $html = (string) ChunkUpload::make('Video', 'source_path')->render();

    expect($html)->toContain('name="source_path"')
        ->and($html)->toContain('x-data="chunkUploader(');
});

it('renders the accept attribute from the profile', function (): void {
    $html = (string) ChunkUpload::make('Subtitle', 'source_path')->profile('subtitle')->render();

    expect($html)->toContain('accept=".srt,.vtt,.ass"');
});

it('hands the endpoints and limits to the alpine component', function (): void {
    $uploader = ChunkUpload::make('Video', 'source_path')
        ->chunkSize(2 * 1024 * 1024)
        ->concurrency(2)
        ->toArray()['uploader'];

    expect($uploader['urls'])->toBe([
        'init' => route('moonshine-chunk-upload.init'),
        'chunk' => route('moonshine-chunk-upload.chunk'),
        'status' => route('moonshine-chunk-upload.status'),
        'finalize' => route('moonshine-chunk-upload.finalize'),
        'abort' => route('moonshine-chunk-upload.abort'),
    ])
        ->and($uploader['chunkSize'])->toBe(2 * 1024 * 1024)
        ->and($uploader['concurrency'])->toBe(2)
        ->and($uploader['profile'])->toBe('video')
        ->and($uploader['extensions'])->toBe(['mp4', 'mov', 'mkv', 'webm', 'm4v'])
        ->and($uploader['storageKey'])->toStartWith('moonshine-chunk-upload:');
});

it('clamps the chunk size to the configured maximum', function (): void {
    $this->reconfigure(['moonshine-chunk-upload.max_chunk_size' => 1024]);

    expect(ChunkUpload::make('Video', 'source_path')->chunkSize(8 * 1024 * 1024)->getChunkSize())->toBe(1024);
});

it('gives every field on a page its own resume key', function (): void {
    $video = ChunkUpload::make('Video', 'video')->toArray()['uploader']['storageKey'];
    $audio = ChunkUpload::make('Audio', 'audio')->toArray()['uploader']['storageKey'];

    expect($video)->not->toBe($audio);
});

it('renders the request log only in debug mode', function (): void {
    expect((string) ChunkUpload::make('Video', 'source_path')->render())->not->toContain('showLogs')
        ->and((string) ChunkUpload::make('Video', 'source_path')->debug()->render())->toContain('showLogs');
});

it('links the stored file in preview mode', function (): void {
    $html = (string) ChunkUpload::make('Video', 'source_path')
        ->disk('public')
        ->previewMode()
        ->fill('videos/clip.mp4')
        ->render();

    expect($html)->toContain('clip.mp4')
        ->and($html)->toContain('<a');
});

it('drops the preview link when downloads are disabled', function (): void {
    $html = (string) ChunkUpload::make('Video', 'source_path')
        ->disk('public')
        ->previewMode()
        ->disableDownload()
        ->fill('videos/clip.mp4')
        ->render();

    expect($html)->toContain('clip.mp4')
        ->and($html)->not->toContain('<a');
});

<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use Illuminate\Support\Facades\Storage;
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

it('takes its extension list from the configured profile', function (): void {
    expect(ChunkUpload::make('Video', 'source_path')->profile('audio')->getAllowedExtensions())
        ->toBe(['mp3', 'aac', 'wav', 'flac', 'm4a', 'ogg']);
});

it('lets a field narrow the profile list', function (): void {
    expect(ChunkUpload::make('Video', 'source_path')->allowedExtensions(['MP4', 'mkv'])->getAllowedExtensions())
        ->toBe(['mp4', 'mkv']);
});

it('claims the finalized upload and stores its new path', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);

    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos');

    $item = $this->submitField($field, ['source_path' => null], $path);

    expect($item['source_path'])->toBe('videos/'.basename($path));
    Storage::disk('local')->assertExists($item['source_path']);
    Storage::disk('local')->assertMissing($path);
});

it('ignores a submitted value that is not a finalized upload', function (): void {
    Storage::disk('local')->put('videos/current.mp4', 'current');

    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos');

    $item = $this->submitField($field, ['source_path' => 'videos/current.mp4'], '../../.env');

    expect($item['source_path'])->toBe('videos/current.mp4');
    Storage::disk('local')->assertExists('videos/current.mp4');
});

it('replaces the previous file and deletes it', function (): void {
    Storage::disk('local')->put('videos/old.mp4', 'old');

    $path  = $this->completeUpload(['twelve-bytes']);
    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos');

    $item = $this->submitField($field, ['source_path' => 'videos/old.mp4'], $path);

    expect($item['source_path'])->toBe('videos/'.basename($path));
    Storage::disk('local')->assertMissing('videos/old.mp4');
});

it('keeps the previous file when deletion is disabled', function (): void {
    Storage::disk('local')->put('videos/old.mp4', 'old');

    $path  = $this->completeUpload(['twelve-bytes']);
    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos')->disableDeleteFiles();

    $this->submitField($field, ['source_path' => 'videos/old.mp4'], $path);

    Storage::disk('local')->assertExists('videos/old.mp4');
});

it('clears the column and the file when an empty value is submitted', function (): void {
    Storage::disk('local')->put('videos/old.mp4', 'old');

    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos');

    $item = $this->submitField($field, ['source_path' => 'videos/old.mp4'], '');

    expect($item['source_path'])->toBeNull();
    Storage::disk('local')->assertMissing('videos/old.mp4');
});

it('leaves an unchanged value alone', function (): void {
    Storage::disk('local')->put('videos/keep.mp4', 'keep');

    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos');

    $item = $this->submitField($field, ['source_path' => 'videos/keep.mp4'], 'videos/keep.mp4');

    expect($item['source_path'])->toBe('videos/keep.mp4');
    Storage::disk('local')->assertExists('videos/keep.mp4');
});

it('leaves the record alone when the field was not submitted at all', function (): void {
    $field = ChunkUpload::make('Video', 'source_path')->disk('local')->dir('videos');

    $item = $this->submitField($field, ['source_path' => 'videos/keep.mp4'], null);

    expect($item['source_path'])->toBe('videos/keep.mp4');
});

it('renames the claimed file through customName', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);

    $field = ChunkUpload::make('Video', 'source_path')
        ->disk('local')
        ->dir('videos')
        ->customName(static fn (): string => 'trailer.mp4');

    $item = $this->submitField($field, ['source_path' => null], $path);

    expect($item['source_path'])->toBe('videos/trailer.mp4');
});

it('stores the file on another disk than the one it was assembled on', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);

    $field = ChunkUpload::make('Video', 'source_path')->disk('remote')->dir('videos');

    $item = $this->submitField($field, ['source_path' => null], $path);

    expect(Storage::disk('remote')->get($item['source_path']))->toBe('twelve-bytes');
    Storage::disk('local')->assertMissing($path);
});

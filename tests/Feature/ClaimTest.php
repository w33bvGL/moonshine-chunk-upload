<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use Illuminate\Support\Facades\Storage;

it('moves a finalized file onto the target disk and directory', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);

    $stored = $this->manager()->claim($path, 'local', 'videos');

    expect($stored)->toBe('videos/'.basename($path));
    Storage::disk('local')->assertExists($stored);
    Storage::disk('local')->assertMissing($path);
});

it('streams the file when the target disk is a different one', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);

    $stored = $this->manager()->claim($path, 'remote', 'videos');

    expect(Storage::disk('remote')->get($stored))->toBe('twelve-bytes');
    Storage::disk('local')->assertMissing($path);
});

it('renames the file through the given callback', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);

    $stored = $this->manager()->claim($path, 'local', 'videos', static fn (): string => 'My Clip.mp4');

    expect($stored)->toBe('videos/My-Clip.mp4');
});

it('never overwrites a file that is already there', function (): void {
    Storage::disk('local')->put('videos/keep.mp4', 'existing');

    $path = $this->completeUpload(['twelve-bytes']);

    $stored = $this->manager()->claim($path, 'local', 'videos', static fn (): string => 'keep.mp4');

    expect($stored)->not->toBe('videos/keep.mp4')
        ->and(Storage::disk('local')->get('videos/keep.mp4'))->toBe('existing')
        ->and(Storage::disk('local')->get($stored))->toBe('twelve-bytes');
});

it('refuses to claim anything that is not a finalized upload', function (string $path): void {
    Storage::disk('local')->put('secret.mp4', 'secret');
    Storage::disk('local')->put('final/nested/deep.mp4', 'nested');

    expect($this->manager()->claim($path, 'local', 'videos'))->toBeNull()
        ->and(Storage::disk('local')->exists('secret.mp4'))->toBeTrue();
})->with([
    'traversal' => 'final/../secret.mp4',
    'absolute' => '/etc/passwd',
    'outside the staging dir' => 'secret.mp4',
    'nested inside the staging dir' => 'final/nested/deep.mp4',
    'empty' => '',
    'prefix lookalike' => 'finalized/x.mp4',
]);

it('refuses to claim a finalized path whose file is gone', function (): void {
    expect($this->manager()->claim('final/missing.mp4', 'local', 'videos'))->toBeNull();
});

it('recognises only the paths it produced itself', function (): void {
    $manager = $this->manager();

    expect($manager->isFinalizedPath('final/abc.mp4'))->toBeTrue()
        ->and($manager->isFinalizedPath('final/../secret.mp4'))->toBeFalse()
        ->and($manager->isFinalizedPath('final/sub/abc.mp4'))->toBeFalse()
        ->and($manager->isFinalizedPath('final/.env'))->toBeFalse()
        ->and($manager->isFinalizedPath('tmp/abc.mp4'))->toBeFalse();
});

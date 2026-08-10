<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use W33bvgl\MoonShineChunkUpload\Events\ChunkUploadCompleted;

it('uploads a file across parallel chunks and assembles it', function (): void {
    $chunks  = ['first-chunk-', 'second-chunk', 'third-chunk!'];
    $content = implode('', $chunks);

    $uploadId = $this->initUpload([
        'size' => \strlen($content),
        'total' => 3,
        'chunk_size' => 12,
    ])->assertOk()->json('upload_id');

    foreach ([2, 0, 1] as $index) {
        $this->sendChunk($uploadId, $index + 1, $chunks[$index])->assertOk();
    }

    $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => $uploadId]))
        ->assertOk()
        ->assertJson(['received' => [1, 2, 3], 'total' => 3]);

    $path = $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $uploadId])
        ->assertOk()
        ->json('path');

    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe($content)
        ->and($path)->toStartWith('final/');

    expect(Storage::disk('local')->exists("tmp/{$uploadId}"))->toBeFalse();
});

it('refuses to finalize while chunks are missing', function (): void {
    $uploadId = $this->initUpload(['size' => 16, 'total' => 2, 'chunk_size' => 8])
        ->assertOk()
        ->json('upload_id');

    $this->sendChunk($uploadId, 1, 'only-one')->assertOk();

    $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $uploadId])
        ->assertStatus(409)
        ->assertJsonPath('missing', [2]);
});

it('rejects disallowed extensions for the profile', function (): void {
    $this->initUpload(['filename' => 'payload.exe'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Unsupported file extension: exe');
});

it('rejects an unknown profile', function (): void {
    $this->initUpload(['profile' => 'nope'])->assertStatus(422);
});

it('rejects a chunk plan that does not match the declared size', function (): void {
    $this->initUpload(['size' => 100, 'chunk_size' => 10, 'total' => 5])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Chunk count does not match the declared file and chunk size');
});

it('rejects a declared size above the configured maximum', function (): void {
    $this->reconfigure(['moonshine-chunk-upload.max_file_size' => 10]);

    $this->initUpload(['size' => 11, 'total' => 1, 'chunk_size' => 11])->assertStatus(422);
});

it('rejects a chunk whose body does not match the declared chunk size', function (): void {
    $uploadId = $this->initUpload(['size' => 24, 'total' => 2, 'chunk_size' => 12])
        ->assertOk()
        ->json('upload_id');

    $this->sendChunk($uploadId, 1, 'too-short')->assertStatus(422);

    // Nothing was accepted, so the upload still reports zero received chunks.
    $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => $uploadId]))
        ->assertOk()
        ->assertJsonPath('received', []);
});

it('rejects a chunk index outside the declared range', function (): void {
    $uploadId = $this->initUpload()->assertOk()->json('upload_id');

    $this->sendChunk($uploadId, 2, 'twelve-bytes')->assertStatus(422);
    $this->sendChunk($uploadId, 0, 'twelve-bytes')->assertStatus(422);
});

it('answers 404 for unknown or malformed upload ids', function (): void {
    $unknown = (string) Str::uuid();

    $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => $unknown]))->assertStatus(404);
    $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $unknown])->assertStatus(404);
    $this->sendChunk($unknown, 1, 'twelve-bytes')->assertStatus(404);
    $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => 'not-a-uuid']))->assertStatus(404);
});

it('loses the race when the upload is already being assembled', function (): void {
    $uploadId = $this->initUpload()->assertOk()->json('upload_id');

    $this->sendChunk($uploadId, 1, 'twelve-bytes')->assertOk();

    // Stand in for a finalize request that claimed the directory first.
    Storage::disk('local')->put("tmp/{$uploadId}.assembling/1.part", 'twelve-bytes');

    $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $uploadId])
        ->assertStatus(409)
        ->assertJsonPath('error', 'File is already being assembled');
});

it('cannot be finalized twice', function (): void {
    $path = $this->completeUpload(['twelve-bytes']);
    $uploadId = basename($path, '.mp4');

    $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $uploadId])
        ->assertStatus(404);
});

it('deletes the temporary upload directory on abort', function (): void {
    $uploadId = $this->initUpload()->assertOk()->json('upload_id');

    $this->deleteJson(route('moonshine-chunk-upload.abort', ['upload_id' => $uploadId]))->assertOk();

    $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => $uploadId]))->assertStatus(404);
    expect(Storage::disk('local')->exists("tmp/{$uploadId}"))->toBeFalse();
});

it('names the assembled file after the upload id by default', function (): void {
    $path = $this->completeUpload(['twelve-bytes'], 'Holy Video.mp4');

    expect(basename($path, '.mp4'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('keeps a sanitized original filename when asked', function (): void {
    $path = $this->completeUpload(['twelve-bytes'], 'Holy Video: part 1.mp4', keepName: true);

    expect($path)->toBe('final/Holy-Video-part-1.mp4');
});

it('never lets a client-supplied filename escape the final directory', function (): void {
    $path = $this->completeUpload(['twelve-bytes'], '../../etc/passwd.mp4', keepName: true);

    expect($path)->toBe('final/passwd.mp4');
});

it('dispatches an event once the file is assembled', function (): void {
    Event::fake([ChunkUploadCompleted::class]);

    $path = $this->completeUpload(['twelve-bytes', 'twelve-bytes']);

    Event::assertDispatched(
        ChunkUploadCompleted::class,
        static fn (ChunkUploadCompleted $event): bool => $event->path === $path
            && $event->meta->total === 2
            && $event->meta->size === 24
            && $event->meta->extension === 'mp4',
    );
});

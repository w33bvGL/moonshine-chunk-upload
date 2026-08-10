<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use W33bvgl\MoonShineChunkUpload\Tests\TestCase;

final class ChunkUploadTest extends TestCase
{
    #[Test]
    public function it_uploads_a_file_across_parallel_chunks_and_assembles_it(): void
    {
        $chunks  = ['first-chunk-', 'second-chunk', 'third-chunk!'];
        $content = implode('', $chunks);

        $init = $this->postJson(route('moonshine-chunk-upload.init'), [
            'filename' => 'video.mp4',
            'size' => strlen($content),
            'total' => count($chunks),
            'profile' => 'video',
        ])->assertOk()->json();

        $uploadId = $init['upload_id'];

        // Out of order, as parallel chunk requests would arrive.
        foreach ([2, 0, 1] as $index) {
            $this->call(
                'POST',
                route('moonshine-chunk-upload.chunk', ['upload_id' => $uploadId, 'index' => $index + 1]),
                content: $chunks[$index],
            )->assertOk();
        }

        $status = $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => $uploadId]))
            ->assertOk()
            ->json();

        self::assertSame([1, 2, 3], $status['received']);

        $finalize = $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $uploadId])
            ->assertOk()
            ->json();

        Storage::disk('local')->assertExists($finalize['path']);
        self::assertSame($content, Storage::disk('local')->get($finalize['path']));
    }

    #[Test]
    public function it_refuses_to_finalize_while_chunks_are_missing(): void
    {
        $init = $this->postJson(route('moonshine-chunk-upload.init'), [
            'filename' => 'video.mp4',
            'size' => 10,
            'total' => 2,
            'profile' => 'video',
        ])->json();

        $this->call('POST', route('moonshine-chunk-upload.chunk', ['upload_id' => $init['upload_id'], 'index' => 1]), content: 'only-one')
            ->assertOk();

        $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $init['upload_id']])
            ->assertStatus(409)
            ->assertJsonPath('missing', [2]);
    }

    #[Test]
    public function it_rejects_disallowed_extensions_for_the_profile(): void
    {
        $this->postJson(route('moonshine-chunk-upload.init'), [
            'filename' => 'payload.exe',
            'size' => 10,
            'total' => 1,
            'profile' => 'video',
        ])->assertStatus(422);
    }

    #[Test]
    public function abort_deletes_the_temporary_upload_directory(): void
    {
        $init = $this->postJson(route('moonshine-chunk-upload.init'), [
            'filename' => 'video.mp4',
            'size' => 10,
            'total' => 1,
            'profile' => 'video',
        ])->json();

        $this->deleteJson(route('moonshine-chunk-upload.abort', ['upload_id' => $init['upload_id']]))
            ->assertOk();

        $this->getJson(route('moonshine-chunk-upload.status', ['upload_id' => $init['upload_id']]))
            ->assertStatus(404);
    }
}

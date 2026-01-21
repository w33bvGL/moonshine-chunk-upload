<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use W33bvgl\MoonShineChunkUpload\Tests\TestCase;

final class ChunkUploadTest extends TestCase
{
    /** @test */
    public function it_can_successfully_upload_a_file_chunk_test(): void
    {
        Storage::fake('local');

        $response = $this->postJson(route('moonshine-chunk.upload'), [
            'file' => UploadedFile::fake()->create('video.mp4', 1000),
            'resumableIdentifier' => 'test-id-123',
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 1,
            'resumableFilename' => 'video.mp4',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $path = $response->json('path');
        Storage::disk('local')->assertExists($path);
    }
}
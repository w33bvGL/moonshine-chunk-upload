<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use W33bvgl\MoonShineChunkUpload\Tests\TestCase;

final class ChunkUploadTest extends TestCase
{
    #[Test]
    public function it_can_successfully_upload_a_file_chunk_test(): void
    {
        $response = $this->postJson(route('moonshine-chunk.upload'), [
            'file' => UploadedFile::fake()->create('video.mp4', 1000),
            'resumableIdentifier' => 'debug-id',
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 1,
            'resumableFilename' => 'video.mp4',
        ]);

        $path = $response->json('path');
        dump("Файл создался в: " . realpath(__DIR__ . '/../storage/' . $path));
        Storage::disk('local')->assertExists($path);
    }
}
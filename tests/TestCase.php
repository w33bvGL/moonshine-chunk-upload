<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;
use W33bvgl\MoonShineChunkUpload\Providers\MoonshineChunkUploadServiceProvider;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadConfig;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

abstract class TestCase extends Orchestra
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->storagePath());
    }

    #[Override]
    protected function tearDown(): void
    {
        File::deleteDirectory($this->storagePath());

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MoonshineChunkUploadServiceProvider::class,
        ];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        foreach (['local', 'public', 'remote'] as $disk) {
            $app['config']->set("filesystems.disks.{$disk}", [
                'driver' => 'local',
                'root' => $this->storagePath()."/{$disk}",
                'url' => "http://localhost/{$disk}",
                'throw' => false,
            ]);
        }

        $app['config']->set('moonshine-chunk-upload.disk', 'local');
        $app['config']->set('moonshine-chunk-upload.tmp_dir', 'tmp');
        $app['config']->set('moonshine-chunk-upload.final_dir', 'final');
    }

    protected function storagePath(): string
    {
        return __DIR__.'/storage/app';
    }

    protected function manager(): ChunkUploadManager
    {
        return $this->app->make(ChunkUploadManager::class);
    }

    /**
     * Re-reads the package config after a test has changed it: the config
     * object is a singleton, so it has to be forgotten explicitly.
     *
     * @param array<string, mixed> $values
     */
    protected function reconfigure(array $values): void
    {
        foreach ($values as $key => $value) {
            config()->set($key, $value);
        }

        $this->app->forgetInstance(ChunkUploadConfig::class);
        $this->app->forgetInstance(ChunkUploadManager::class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function initUpload(array $payload = []): TestResponse
    {
        return $this->postJson(route('moonshine-chunk-upload.init'), [
            'filename' => 'video.mp4',
            'size' => 12,
            'total' => 1,
            'chunk_size' => 12,
            'profile' => 'video',
            ...$payload,
        ]);
    }

    protected function sendChunk(string $uploadId, int $index, string $content): TestResponse
    {
        return $this->call(
            'POST',
            route('moonshine-chunk-upload.chunk', ['upload_id' => $uploadId, 'index' => $index]),
            content: $content,
        );
    }

    /**
     * Runs the field's apply pipeline against a plain array "model", with
     * `$submitted` standing in for what the hidden input carried (null meaning
     * the field was not part of the request at all).
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    protected function submitField(ChunkUpload $field, array $item, ?string $submitted): array
    {
        $this->app->instance('request', Request::create(
            '/',
            'POST',
            $submitted === null ? [] : ['source_path' => $submitted],
        ));

        /** @var array<string, mixed> */
        return $field->apply(static fn (array $data): array => $data, $item);
    }

    /**
     * Runs a whole upload and returns the finalized path.
     *
     * @param list<string> $chunks
     */
    protected function completeUpload(array $chunks, string $filename = 'video.mp4', bool $keepName = false): string
    {
        $content = implode('', $chunks);

        $uploadId = $this->initUpload([
            'filename' => $filename,
            'size' => \strlen($content),
            'total' => \count($chunks),
            'chunk_size' => \strlen($chunks[0]),
            'keep_name' => $keepName,
        ])->assertOk()->json('upload_id');

        foreach ($chunks as $index => $chunk) {
            $this->sendChunk($uploadId, $index + 1, $chunk)->assertOk();
        }

        return (string) $this->postJson(route('moonshine-chunk-upload.finalize'), ['upload_id' => $uploadId])
            ->assertOk()
            ->json('path');
    }
}

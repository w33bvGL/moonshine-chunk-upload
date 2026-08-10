<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed view over config/moonshine-chunk-upload.php.
 *
 * Bound as a singleton, so every consumer (controller, field, prune command)
 * reads the same normalised values instead of re-casting config() calls.
 */
final readonly class ChunkUploadConfig
{
    /**
     * @param array<string, list<string>> $profiles
     */
    public function __construct(
        public string $disk,
        public string $tmpDir,
        public string $finalDir,
        public int $maxChunkSize,
        public int $maxFileSize,
        public int $tmpTtlHours,
        public int $finalTtlHours,
        public array $profiles,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        /** @var array<string, list<string>> $profiles */
        $profiles = $config->get('moonshine-chunk-upload.profiles', []);

        return new self(
            disk: (string) $config->get('moonshine-chunk-upload.disk', 'local'),
            tmpDir: trim((string) $config->get('moonshine-chunk-upload.tmp_dir', 'chunked-uploads/tmp'), '/'),
            finalDir: trim((string) $config->get('moonshine-chunk-upload.final_dir', 'chunked-uploads/final'), '/'),
            maxChunkSize: (int) $config->get('moonshine-chunk-upload.max_chunk_size', 16 * 1024 * 1024),
            maxFileSize: (int) $config->get('moonshine-chunk-upload.max_file_size', 32 * 1024 * 1024 * 1024),
            tmpTtlHours: (int) $config->get('moonshine-chunk-upload.tmp_ttl_hours', 24),
            finalTtlHours: (int) $config->get('moonshine-chunk-upload.final_ttl_hours', 72),
            profiles: $profiles,
        );
    }

    /**
     * @return list<string>
     */
    public function extensionsFor(string $profile): array
    {
        return $this->profiles[$profile] ?? [];
    }

    /**
     * @return list<string>
     */
    public function profileNames(): array
    {
        return array_values(array_keys($this->profiles));
    }

    /**
     * Upper bound on the chunk count, used to reject absurd `total` values
     * before a single byte is written.
     */
    public function maxChunks(): int
    {
        return (int) ceil($this->maxFileSize / max(1, $this->maxChunkSize));
    }

    public function tmpDirFor(string $uploadId): string
    {
        return "{$this->tmpDir}/{$uploadId}";
    }
}

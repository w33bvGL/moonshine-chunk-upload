<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Support;

/**
 * The `meta.json` written next to the parts of an in-flight upload.
 *
 * Everything the server needs to validate later chunk/finalize requests lives
 * here, so those requests carry nothing but an upload id — a client cannot
 * grow the declared size or swap the extension halfway through.
 */
final readonly class UploadMeta
{
    public function __construct(
        public string $filename,
        public string $extension,
        public int $size,
        public int $total,
        public int $chunkSize,
        public string $profile,
        public bool $keepOriginalName,
        public string $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public static function fromArray(array $meta): ?self
    {
        if (
            ! \is_string($meta['filename'] ?? null)
            || ! \is_string($meta['extension'] ?? null)
            || ! \is_int($meta['size'] ?? null)
            || ! \is_int($meta['total'] ?? null)
            || ! \is_int($meta['chunk_size'] ?? null)
            || ! \is_string($meta['profile'] ?? null)
            || ! \is_string($meta['created_at'] ?? null)
        ) {
            return null;
        }

        return new self(
            filename: $meta['filename'],
            extension: $meta['extension'],
            size: $meta['size'],
            total: $meta['total'],
            chunkSize: $meta['chunk_size'],
            profile: $meta['profile'],
            keepOriginalName: (bool) ($meta['keep_original_name'] ?? false),
            createdAt: $meta['created_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'extension' => $this->extension,
            'size' => $this->size,
            'total' => $this->total,
            'chunk_size' => $this->chunkSize,
            'profile' => $this->profile,
            'keep_original_name' => $this->keepOriginalName,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Bytes the given 1-based chunk index must carry: every chunk is exactly
     * `chunkSize` long except the last one, which carries the remainder.
     */
    public function expectedChunkBytes(int $index): int
    {
        if ($index < $this->total) {
            return $this->chunkSize;
        }

        return $this->size - ($this->total - 1) * $this->chunkSize;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Support;

use JsonSerializable;

final readonly class UploadMeta implements JsonSerializable
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
     * @param array<array-key, mixed> $meta
     */
    public static function fromArray(array $meta): ?self
    {
        $size      = $meta['size'] ?? null;
        $total     = $meta['total'] ?? null;
        $chunkSize = $meta['chunk_size'] ?? null;

        if (
            ! \is_string($meta['filename'] ?? null)
            || ! \is_string($meta['extension'] ?? null)
            || ! \is_string($meta['profile'] ?? null)
            || ! \is_string($meta['created_at'] ?? null)
            || ! \is_int($size) || $size < 1
            || ! \is_int($total) || $total < 1
            || ! \is_int($chunkSize) || $chunkSize < 1
            || $total !== (int) ceil($size / $chunkSize)
        ) {
            return null;
        }

        return new self(
            filename: $meta['filename'],
            extension: $meta['extension'],
            size: $size,
            total: $total,
            chunkSize: $chunkSize,
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
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function expectedChunkBytes(int $index): int
    {
        return $index < $this->total
            ? $this->chunkSize
            : $this->size - ($this->total - 1) * $this->chunkSize;
    }
}

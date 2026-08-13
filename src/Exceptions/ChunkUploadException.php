<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ChunkUploadException extends RuntimeException
{
    /**
     * @param list<int> $missing
     */
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly array $missing = [],
    ) {
        parent::__construct($message);
    }

    public static function unsupportedExtension(string $extension): self
    {
        return new self("Unsupported file extension: {$extension}", Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function unknownProfile(string $profile): self
    {
        return new self("Unknown upload profile: {$profile}", Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function fileTooLarge(): self
    {
        return new self('Declared file size exceeds the configured limit', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function chunkSizeTooLarge(): self
    {
        return new self('Declared chunk size exceeds the configured limit', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function chunkPlanMismatch(): self
    {
        return new self(
            'Chunk count does not match the declared file and chunk size',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notFound(): self
    {
        return new self('Upload not found or already finalized', Response::HTTP_NOT_FOUND);
    }

    public static function chunkOutOfRange(int $index): self
    {
        return new self("Chunk index {$index} is out of range", Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function invalidChunkSize(int $index): self
    {
        return new self("Chunk {$index} does not match the declared chunk size", Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param list<int> $missing
     */
    public static function incompleteUpload(array $missing): self
    {
        return new self('Not all chunks have been uploaded', Response::HTTP_CONFLICT, $missing);
    }

    public static function alreadyAssembling(): self
    {
        return new self('File is already being assembled', Response::HTTP_CONFLICT);
    }

    public static function assemblyFailed(string $reason): self
    {
        return new self("Unable to assemble the file: {$reason}", Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public static function sizeMismatch(): self
    {
        return new self('Assembled file size does not match the declared size', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function unsupportedDisk(string $disk): self
    {
        return new self(
            "Disk [{$disk}] is not a local disk. Chunked uploads are assembled on disk and "
            .'require a local filesystem; point moonshine-chunk-upload.disk at one.',
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(
            array_filter([
                'error' => $this->getMessage(),
                'missing' => $this->missing,
            ]),
            $this->status,
        );
    }

    public function report(): bool
    {
        return $this->status < Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use W33bvgl\MoonShineChunkUpload\Exceptions\ChunkUploadException;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

final class ChunkUploadController extends Controller
{
    public function __construct(private readonly ChunkUploadManager $manager) {}

    public function init(Request $request): JsonResponse
    {
        $config = $this->manager->config();

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$config->maxFileSize],
            'chunk_size' => ['required', 'integer', 'min:1', 'max:'.$config->maxChunkSize],
            'total' => ['required', 'integer', 'min:1', 'max:'.$config->maxChunks()],
            'profile' => ['required', 'string', 'in:'.implode(',', $config->profileNames())],
            'keep_name' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'upload_id' => $this->manager->start(
                filename: (string) $validated['filename'],
                size: (int) $validated['size'],
                total: (int) $validated['total'],
                chunkSize: (int) $validated['chunk_size'],
                profile: (string) $validated['profile'],
                keepOriginalName: (bool) ($validated['keep_name'] ?? false),
            ),
        ]);
    }

    public function chunk(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $index    = $request->integer('index');

        $this->manager->receiveChunk($uploadId, $index, $request->getContent(asResource: true));

        return response()->json([
            'status' => 'chunk-received',
            'index' => $index,
            'received' => \count($this->manager->receivedIndexes($uploadId)),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $meta     = $this->manager->meta($uploadId) ?? throw ChunkUploadException::notFound();

        return response()->json([
            'received' => $this->manager->receivedIndexes($uploadId),
            'total' => $meta->total,
        ]);
    }

    public function finalize(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'completed',
            'path' => $this->manager->finalize($this->uploadId($request)),
        ]);
    }

    public function abort(Request $request): JsonResponse
    {
        $this->manager->abort($this->uploadId($request));

        return response()->json(['status' => 'aborted']);
    }

    private function uploadId(Request $request): string
    {
        $uploadId = $request->string('upload_id')->toString();

        return Str::isUuid($uploadId)
            ? $uploadId
            : throw ChunkUploadException::notFound();
    }
}

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
use W33bvgl\MoonShineChunkUpload\Support\UploadMeta;

/**
 * The HTTP surface of the upload protocol:
 *
 *   POST   init     {filename, size, total, chunk_size, profile}  -> {upload_id}
 *   POST   chunk    ?upload_id&index  (raw body)                  -> {received}
 *   GET    status   ?upload_id                                    -> {received: [...], total}
 *   POST   finalize {upload_id}                                   -> {path}
 *   DELETE abort    ?upload_id                                    -> {status}
 *
 * Everything past `init` is validated against the frozen `meta.json`, so the
 * only thing a later request may carry is an upload id.
 */
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

        return $this->guard(fn (): JsonResponse => response()->json([
            'upload_id' => $this->manager->start(
                filename: (string) $validated['filename'],
                size: (int) $validated['size'],
                total: (int) $validated['total'],
                chunkSize: (int) $validated['chunk_size'],
                profile: (string) $validated['profile'],
                keepOriginalName: (bool) ($validated['keep_name'] ?? false),
            ),
        ]));
    }

    public function chunk(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $index    = $request->integer('index');

        if ($uploadId === null) {
            return $this->error(ChunkUploadException::notFound());
        }

        return $this->guard(function () use ($request, $uploadId, $index): JsonResponse {
            $this->manager->receiveChunk($uploadId, $index, $request->getContent(asResource: true));

            return response()->json([
                'status' => 'chunk-received',
                'index' => $index,
                'received' => \count($this->manager->receivedIndexes($uploadId)),
            ]);
        });
    }

    public function status(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $meta     = $uploadId === null ? null : $this->manager->meta($uploadId);

        if ($uploadId === null || ! $meta instanceof UploadMeta) {
            return $this->error(ChunkUploadException::notFound());
        }

        return response()->json([
            'received' => $this->manager->receivedIndexes($uploadId),
            'total' => $meta->total,
        ]);
    }

    public function finalize(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);

        if ($uploadId === null) {
            return $this->error(ChunkUploadException::notFound());
        }

        return $this->guard(fn (): JsonResponse => response()->json([
            'status' => 'completed',
            'path' => $this->manager->finalize($uploadId),
        ]));
    }

    public function abort(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);

        if ($uploadId !== null) {
            $this->manager->abort($uploadId);
        }

        return response()->json(['status' => 'aborted']);
    }

    /**
     * @param callable(): JsonResponse $handler
     */
    private function guard(callable $handler): JsonResponse
    {
        try {
            return $handler();
        } catch (ChunkUploadException $e) {
            return $this->error($e);
        }
    }

    private function error(ChunkUploadException $e): JsonResponse
    {
        return response()->json(
            array_filter([
                'error' => $e->getMessage(),
                'missing' => $e->missing,
            ]),
            $e->status,
        );
    }

    private function uploadId(Request $request): ?string
    {
        $uploadId = $request->string('upload_id')->toString();

        return Str::isUuid($uploadId) ? $uploadId : null;
    }
}

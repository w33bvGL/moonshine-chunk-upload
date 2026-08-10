<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Parallel-safe chunked upload protocol:
 *
 *   POST   init     {filename, size, total, profile}   -> {upload_id}
 *   POST   chunk    ?upload_id&index (raw body)        -> {received}
 *   GET    status   ?upload_id                         -> {received: [...], total}
 *   POST   finalize {upload_id}                        -> {path}
 *   DELETE abort    ?upload_id                         -> {status}
 *
 * Every chunk is stored as its own part file, so any number of chunks may
 * be uploaded concurrently and retried idempotently. Finalize atomically
 * claims the upload (rename) before assembling, so double-submits are safe.
 */
final class ChunkUploadController extends Controller
{
    public function init(Request $request): JsonResponse
    {
        /** @var array<string, list<string>> $profiles */
        $profiles = config('moonshine-chunk-upload.profiles');

        $maxFileSize  = (int) config('moonshine-chunk-upload.max_file_size');
        $maxChunkSize = (int) config('moonshine-chunk-upload.max_chunk_size');

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', "max:{$maxFileSize}"],
            'total' => ['required', 'integer', 'min:1', 'max:'.(int) ceil($maxFileSize / $maxChunkSize)],
            'profile' => ['required', 'string', 'in:'.implode(',', array_keys($profiles))],
        ]);

        $extension = strtolower(pathinfo((string) $validated['filename'], PATHINFO_EXTENSION));

        if (! in_array($extension, $profiles[$validated['profile']], true)) {
            return response()->json(['error' => 'Unsupported file extension'], 422);
        }

        $uploadId = (string) Str::uuid();

        $this->disk()->makeDirectory($this->tmpDir($uploadId));

        $this->writeMeta($uploadId, [
            'filename' => $validated['filename'],
            'extension' => $extension,
            'size' => (int) $validated['size'],
            'total' => (int) $validated['total'],
            'profile' => $validated['profile'],
            'created_at' => now()->toIso8601String(),
        ]);

        return response()->json(['upload_id' => $uploadId]);
    }

    public function chunk(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $index    = $request->integer('index');

        if ($uploadId === null || $index < 1) {
            return response()->json(['error' => 'Invalid upload parameters'], 422);
        }

        $meta = $this->readMeta($uploadId);

        if ($meta === null) {
            return response()->json(['error' => 'Upload not found or already finalized'], 404);
        }

        if ($index > $meta['total']) {
            return response()->json(['error' => 'Chunk index out of range'], 422);
        }

        $content = $request->getContent();

        if ($content === '' || strlen($content) > (int) config('moonshine-chunk-upload.max_chunk_size')) {
            return response()->json(['error' => 'Invalid chunk size'], 422);
        }

        // Write to a private tmp name, then rename: atomic on the same
        // filesystem, so parallel retries of the same index can't interleave.
        $partAbsolute = $this->disk()->path($this->tmpDir($uploadId)."/{$index}.part");
        $tmpAbsolute  = $partAbsolute.'.'.getmypid().'.tmp';

        File::put($tmpAbsolute, $content);
        File::move($tmpAbsolute, $partAbsolute);

        return response()->json([
            'status' => 'chunk-received',
            'index' => $index,
            'received' => count($this->receivedIndexes($uploadId)),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $meta     = $uploadId === null ? null : $this->readMeta($uploadId);

        if ($uploadId === null || $meta === null) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        return response()->json([
            'received' => $this->receivedIndexes($uploadId),
            'total' => $meta['total'],
        ]);
    }

    public function finalize(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);
        $meta     = $uploadId === null ? null : $this->readMeta($uploadId);

        if ($uploadId === null || $meta === null) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $missing = array_diff(range(1, $meta['total']), $this->receivedIndexes($uploadId));

        if ($missing !== []) {
            return response()->json([
                'error' => 'Not all chunks have been uploaded',
                'missing' => array_values($missing),
            ], 409);
        }

        // Atomically claim the upload so a double finalize gets a 409
        // instead of assembling the same parts twice.
        $claimedDir = $this->tmpDir($uploadId).'.assembling';

        if (! @rename(
            $this->disk()->path($this->tmpDir($uploadId)),
            $this->disk()->path($claimedDir),
        )) {
            return response()->json(['error' => 'File is already being assembled'], 409);
        }

        $finalRelative = config('moonshine-chunk-upload.final_dir')."/{$uploadId}.{$meta['extension']}";
        $finalAbsolute = $this->disk()->path($finalRelative);

        File::ensureDirectoryExists(dirname($finalAbsolute));

        $out = fopen($finalAbsolute, 'wb');

        if ($out === false) {
            return response()->json(['error' => 'Unable to create the assembled file'], 500);
        }

        for ($index = 1; $index <= $meta['total']; $index++) {
            $in = fopen($this->disk()->path("{$claimedDir}/{$index}.part"), 'rb');

            if ($in === false) {
                fclose($out);

                return response()->json(['error' => "Unable to read chunk {$index}"], 500);
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);

        $this->disk()->deleteDirectory($claimedDir);

        if ((int) filesize($finalAbsolute) !== $meta['size']) {
            File::delete($finalAbsolute);

            return response()->json(['error' => 'Assembled file size does not match the declared size'], 422);
        }

        return response()->json([
            'status' => 'completed',
            'path' => $finalRelative,
        ]);
    }

    public function abort(Request $request): JsonResponse
    {
        $uploadId = $this->uploadId($request);

        if ($uploadId !== null) {
            $this->disk()->deleteDirectory($this->tmpDir($uploadId));
        }

        return response()->json(['status' => 'aborted']);
    }

    private function uploadId(Request $request): ?string
    {
        $uploadId = $request->string('upload_id')->toString();

        return Str::isUuid($uploadId) ? $uploadId : null;
    }

    private function tmpDir(string $uploadId): string
    {
        return config('moonshine-chunk-upload.tmp_dir')."/{$uploadId}";
    }

    /**
     * @return list<int>
     */
    private function receivedIndexes(string $uploadId): array
    {
        $indexes = [];

        foreach ($this->disk()->files($this->tmpDir($uploadId)) as $file) {
            if (preg_match('/\/(\d+)\.part$/', (string) $file, $matches)) {
                $indexes[] = (int) $matches[1];
            }
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * @param array{filename: string, extension: string, size: int, total: int, profile: string, created_at: string} $meta
     */
    private function writeMeta(string $uploadId, array $meta): void
    {
        $this->disk()->put(
            $this->tmpDir($uploadId).'/meta.json',
            json_encode($meta, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{filename: string, extension: string, size: int, total: int, profile: string, created_at: string}|null
     */
    private function readMeta(string $uploadId): ?array
    {
        $raw = rescue(fn (): ?string => $this->disk()->get($this->tmpDir($uploadId).'/meta.json'), report: false);

        if (! is_string($raw)) {
            return null;
        }

        $meta = json_decode($raw, true);

        if (! is_array($meta)
            || ! is_string($meta['filename'] ?? null)
            || ! is_string($meta['extension'] ?? null)
            || ! is_int($meta['size'] ?? null)
            || ! is_int($meta['total'] ?? null)
            || ! is_string($meta['profile'] ?? null)
            || ! is_string($meta['created_at'] ?? null)
        ) {
            return null;
        }

        return $meta;
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk((string) config('moonshine-chunk-upload.disk'));
    }
}

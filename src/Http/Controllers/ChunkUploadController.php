<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Throwable;

class ChunkUploadController extends Controller
{
    public function upload(FileReceiver $receiver): JsonResponse
    {
        $identifier  = request('resumableIdentifier', 'unknown');
        $chunkNumber = (int) request('resumableChunkNumber', 0);
        $totalChunks = (int) request('resumableTotalChunks', 0);
        $filename    = request('resumableFilename', 'unknown');

        $context = ['id' => $identifier, 'file' => $filename, 'chunk' => "$chunkNumber/$totalChunks"];

        try {
            if ($receiver->isUploaded() === false) {
                throw new UploadMissingFileException;
            }

            $receive = $receiver->receive();

            if ($receive->isFinished()) {
                return $this->processFinishedFile($receive->getFile(), $identifier);
            }

            $handler = $receive->handler();
            return response()->json([
                'done' => $handler->getPercentageDone(),
                'status' => true,
            ], 201);

        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'mkdir(): File exists')) {
                return response()->json(['done' => 0, 'status' => true]);
            }

            Log::error('ChunkUpload Error: '.$e->getMessage(), $context);

            return response()->json([
                'success' => false,
                'error' => 'Server Error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * @throws Throwable
     */
    protected function processFinishedFile(UploadedFile $file, string $identifier): JsonResponse
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(0);

        $diskName = 'local';
        $targetDir = 'temp_uploads';

        $disk = Storage::disk($diskName);
        $extension = $file->getClientOriginalExtension();
        $finalName = Str::random(16).'_'.$identifier.'.'.$extension;
        $finalPath = "{$targetDir}/{$finalName}";

        try {
            $disk->putFileAs($targetDir, $file, $finalName);

            @unlink($file->getPathname());

            return response()->json([
                'path' => $finalPath,
                'success' => true,
            ]);

        } catch (Throwable $e) {
            if ($disk->exists($finalPath)) {
                $disk->delete($finalPath);
            }
            throw $e;
        }
    }
}
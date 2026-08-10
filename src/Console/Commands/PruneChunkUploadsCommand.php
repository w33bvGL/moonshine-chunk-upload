<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class PruneChunkUploadsCommand extends Command
{
    protected $signature = 'chunk-upload:prune';

    protected $description = 'Delete stale temporary chunks and orphaned assembled files';

    public function handle(): int
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('moonshine-chunk-upload.disk'));

        $prunedTmp   = $this->pruneTmpDirectories($disk);
        $prunedFinal = $this->pruneFinalFiles($disk);

        $this->info("Pruned {$prunedTmp} stale tmp upload(s), {$prunedFinal} orphaned final file(s).");

        return self::SUCCESS;
    }

    private function pruneTmpDirectories(FilesystemAdapter $disk): int
    {
        $threshold = Carbon::now()->subHours((int) config('moonshine-chunk-upload.tmp_ttl_hours'));
        $pruned    = 0;

        foreach ($disk->directories((string) config('moonshine-chunk-upload.tmp_dir')) as $directory) {
            $modifiedAt = Carbon::createFromTimestamp(File::lastModified($disk->path($directory)));

            if ($modifiedAt->isBefore($threshold)) {
                $disk->deleteDirectory($directory);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function pruneFinalFiles(FilesystemAdapter $disk): int
    {
        $threshold = Carbon::now()->subHours((int) config('moonshine-chunk-upload.final_ttl_hours'));
        $pruned    = 0;

        foreach ($disk->files((string) config('moonshine-chunk-upload.final_dir')) as $file) {
            if (Carbon::createFromTimestamp($disk->lastModified($file))->isBefore($threshold)) {
                $disk->delete($file);
                $pruned++;
            }
        }

        return $pruned;
    }
}

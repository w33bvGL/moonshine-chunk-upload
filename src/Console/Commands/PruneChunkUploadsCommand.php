<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Console\Commands;

use Illuminate\Console\Command;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

final class PruneChunkUploadsCommand extends Command
{
    protected $signature = 'chunk-upload:prune
                            {--tmp-hours= : Override the tmp TTL from the config}
                            {--final-hours= : Override the finalized-file TTL from the config}
                            {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete stale temporary chunks and orphaned assembled files';

    public function handle(ChunkUploadManager $manager): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tmp   = $manager->pruneTmp($this->hours('tmp-hours'), $dryRun);
        $final = $manager->pruneFinal($this->hours('final-hours'), $dryRun);

        $this->info(sprintf(
            '%s %d stale tmp upload(s), %d orphaned final file(s).',
            $dryRun ? 'Would prune' : 'Pruned',
            $tmp,
            $final,
        ));

        return self::SUCCESS;
    }

    private function hours(string $option): ?int
    {
        $value = $this->option($option);

        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}

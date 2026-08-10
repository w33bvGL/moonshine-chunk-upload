<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
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

        $tmpHours   = $this->hours('tmp-hours');
        $finalHours = $this->hours('final-hours');

        $prunedTmp   = $manager->pruneTmp($tmpHours, $dryRun);
        $prunedFinal = $manager->pruneFinal($finalHours, $dryRun);

        $this->info(
            ($dryRun ? 'Would prune ' : 'Pruned ')
            ."{$prunedTmp} stale tmp upload(s), {$prunedFinal} orphaned final file(s)."
        );

        return self::SUCCESS;
    }

    private function hours(string $option): ?int
    {
        $value = $this->option($option);

        return $value === null || $value === '' ? null : (int) $value;
    }
}

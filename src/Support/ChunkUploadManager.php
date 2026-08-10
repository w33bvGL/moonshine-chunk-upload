<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Support;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use W33bvgl\MoonShineChunkUpload\Events\ChunkUploadCompleted;
use W33bvgl\MoonShineChunkUpload\Exceptions\ChunkUploadException;

/**
 * The whole chunked-upload protocol, independent of HTTP.
 *
 * Every upload owns a tmp directory holding one file per chunk plus a
 * `meta.json`, which makes parallel chunk requests race-free: a chunk is
 * written under a private name and renamed into place, so two retries of the
 * same index can never interleave. Finalize claims the directory with a single
 * rename before assembling, so a double submit loses the race instead of
 * assembling the same parts twice.
 */
final readonly class ChunkUploadManager
{
    private const COPY_BUFFER = 1048576;

    public function __construct(private ChunkUploadConfig $config) {}

    public function config(): ChunkUploadConfig
    {
        return $this->config;
    }

    /**
     * Registers an upload and returns its id. Everything the later requests are
     * validated against (size, chunk plan, extension) is frozen here.
     */
    public function start(
        string $filename,
        int $size,
        int $total,
        int $chunkSize,
        string $profile,
        bool $keepOriginalName = false,
    ): string {
        $this->assertLocalDisk();

        $extensions = $this->config->extensionsFor($profile);

        if ($extensions === []) {
            throw ChunkUploadException::unknownProfile($profile);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! \in_array($extension, $extensions, true)) {
            throw ChunkUploadException::unsupportedExtension($extension);
        }

        if ($size < 1 || $size > $this->config->maxFileSize) {
            throw ChunkUploadException::fileTooLarge();
        }

        if ($chunkSize < 1 || $chunkSize > $this->config->maxChunkSize) {
            throw ChunkUploadException::chunkSizeTooLarge();
        }

        if ($total < 1 || $total !== (int) ceil($size / $chunkSize)) {
            throw ChunkUploadException::chunkPlanMismatch();
        }

        $uploadId = (string) Str::uuid();

        $meta = new UploadMeta(
            filename: $filename,
            extension: $extension,
            size: $size,
            total: $total,
            chunkSize: $chunkSize,
            profile: $profile,
            keepOriginalName: $keepOriginalName,
            createdAt: Carbon::now()->toIso8601String(),
        );

        $this->disk()->makeDirectory($this->config->tmpDirFor($uploadId));

        $this->disk()->put(
            $this->config->tmpDirFor($uploadId).'/meta.json',
            json_encode($meta->toArray(), JSON_THROW_ON_ERROR),
        );

        return $uploadId;
    }

    /**
     * Stores a single chunk, streaming it to disk instead of buffering the whole
     * body, and returns the number of bytes written.
     *
     * @param resource|string $content
     */
    public function receiveChunk(string $uploadId, int $index, mixed $content): int
    {
        $meta = $this->meta($uploadId);

        if ($meta === null) {
            throw ChunkUploadException::notFound();
        }

        if ($index < 1 || $index > $meta->total) {
            throw ChunkUploadException::chunkOutOfRange($index);
        }

        $expected = $meta->expectedChunkBytes($index);

        $partAbsolute = $this->disk()->path($this->config->tmpDirFor($uploadId)."/{$index}.part");
        $tmpAbsolute  = $partAbsolute.'.'.getmypid().'.tmp';

        $written = $this->writeCapped($content, $tmpAbsolute, $expected);

        if ($written !== $expected) {
            File::delete($tmpAbsolute);

            throw ChunkUploadException::invalidChunkSize($index);
        }

        // Atomic on the same filesystem: a parallel retry of the same index
        // either wins or loses the rename, never produces a torn part file.
        if (! @rename($tmpAbsolute, $partAbsolute)) {
            File::delete($tmpAbsolute);

            throw ChunkUploadException::assemblyFailed("chunk {$index} could not be stored");
        }

        return $written;
    }

    /**
     * @return list<int>
     */
    public function receivedIndexes(string $uploadId): array
    {
        $indexes = [];

        foreach ($this->disk()->files($this->config->tmpDirFor($uploadId)) as $file) {
            if (preg_match('#/(\d+)\.part$#', $file, $matches) === 1) {
                $indexes[] = (int) $matches[1];
            }
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * Concatenates the parts into the final file and returns its path, relative
     * to the upload disk.
     */
    public function finalize(string $uploadId): string
    {
        $meta = $this->meta($uploadId);

        if ($meta === null) {
            throw ChunkUploadException::notFound();
        }

        $missing = array_values(array_diff(range(1, $meta->total), $this->receivedIndexes($uploadId)));

        if ($missing !== []) {
            throw ChunkUploadException::incompleteUpload($missing);
        }

        $claimedDir = $this->config->tmpDirFor($uploadId).'.assembling';

        if (! @rename(
            $this->disk()->path($this->config->tmpDirFor($uploadId)),
            $this->disk()->path($claimedDir),
        )) {
            throw ChunkUploadException::alreadyAssembling();
        }

        $relative = $this->config->finalDir.'/'.$this->finalName($uploadId, $meta);
        $absolute = $this->disk()->path($relative);

        File::ensureDirectoryExists(\dirname($absolute));

        $out = fopen($absolute, 'wb');

        if ($out === false) {
            $this->disk()->deleteDirectory($claimedDir);

            throw ChunkUploadException::assemblyFailed('the destination file could not be created');
        }

        try {
            for ($index = 1; $index <= $meta->total; $index++) {
                $in = fopen($this->disk()->path("{$claimedDir}/{$index}.part"), 'rb');

                if ($in === false) {
                    throw ChunkUploadException::assemblyFailed("chunk {$index} could not be read");
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } catch (ChunkUploadException $e) {
            fclose($out);
            File::delete($absolute);
            $this->disk()->deleteDirectory($claimedDir);

            throw $e;
        }

        fclose($out);

        $this->disk()->deleteDirectory($claimedDir);

        if ((int) filesize($absolute) !== $meta->size) {
            File::delete($absolute);

            throw ChunkUploadException::sizeMismatch();
        }

        event(new ChunkUploadCompleted($uploadId, $relative, $meta));

        return $relative;
    }

    /**
     * Drops an upload's parts. A directory already claimed by finalize is left
     * alone — pulling it out from under an in-flight assembly would corrupt the
     * result; the prune command sweeps those up instead.
     */
    public function abort(string $uploadId): void
    {
        $this->disk()->deleteDirectory($this->config->tmpDirFor($uploadId));
    }

    public function meta(string $uploadId): ?UploadMeta
    {
        $raw = rescue(
            fn (): ?string => $this->disk()->get($this->config->tmpDirFor($uploadId).'/meta.json'),
            report: false,
        );

        if (! \is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? UploadMeta::fromArray($decoded) : null;
    }

    /**
     * True only for paths this package itself produced, so a tampered form value
     * can never point the field at an arbitrary file.
     */
    public function isFinalizedPath(string $path): bool
    {
        $prefix = $this->config->finalDir.'/';

        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $name = substr($path, \strlen($prefix));

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) === 1
            && ! str_contains($name, '..');
    }

    /**
     * Moves a finalized file out of the staging directory onto the disk the
     * field owns, and returns the stored path (or null when the value is not a
     * finalized upload, or has already been claimed).
     *
     * @param null|Closure(string): string $rename
     */
    public function claim(string $path, string $toDisk, string $toDir = '', ?Closure $rename = null): ?string
    {
        if (! $this->isFinalizedPath($path) || ! $this->disk()->exists($path)) {
            return null;
        }

        $name = basename($path);

        if ($rename !== null) {
            $name = $this->sanitizeName($rename($name), pathinfo($name, PATHINFO_EXTENSION));
        }

        $dir      = trim($toDir, '/');
        $target   = Storage::disk($toDisk);
        $relative = $this->uniquePath($target, ($dir === '' ? '' : $dir.'/').$name);

        if ($toDisk === $this->config->disk) {
            $this->disk()->move($path, $relative);

            return $relative;
        }

        $stream = $this->disk()->readStream($path);

        if (! \is_resource($stream)) {
            return null;
        }

        $target->writeStream($relative, $stream);
        fclose($stream);
        $this->disk()->delete($path);

        return $relative;
    }

    /**
     * Deletes tmp directories of uploads that were never finalized.
     */
    public function pruneTmp(?int $hours = null, bool $dryRun = false): int
    {
        $threshold = Carbon::now()->subHours($hours ?? $this->config->tmpTtlHours);
        $pruned    = 0;

        foreach ($this->disk()->directories($this->config->tmpDir) as $directory) {
            $modifiedAt = Carbon::createFromTimestamp(File::lastModified($this->disk()->path($directory)));

            if ($modifiedAt->isAfter($threshold)) {
                continue;
            }

            if (! $dryRun) {
                $this->disk()->deleteDirectory($directory);
            }

            $pruned++;
        }

        return $pruned;
    }

    /**
     * Deletes assembled files that no field ever claimed — an upload that
     * finished but whose form was never submitted.
     */
    public function pruneFinal(?int $hours = null, bool $dryRun = false): int
    {
        $threshold = Carbon::now()->subHours($hours ?? $this->config->finalTtlHours);
        $pruned    = 0;

        foreach ($this->disk()->files($this->config->finalDir) as $file) {
            if (Carbon::createFromTimestamp($this->disk()->lastModified($file))->isAfter($threshold)) {
                continue;
            }

            if (! $dryRun) {
                $this->disk()->delete($file);
            }

            $pruned++;
        }

        return $pruned;
    }

    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($this->config->disk);
    }

    private function assertLocalDisk(): void
    {
        if (! $this->disk()->getAdapter() instanceof LocalFilesystemAdapter) {
            throw ChunkUploadException::unsupportedDisk($this->config->disk);
        }
    }

    private function finalName(string $uploadId, UploadMeta $meta): string
    {
        if (! $meta->keepOriginalName) {
            return "{$uploadId}.{$meta->extension}";
        }

        $name = $this->sanitizeName($meta->filename, $meta->extension);

        if ($this->disk()->exists($this->config->finalDir.'/'.$name)) {
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $name = $stem.'-'.substr($uploadId, 0, 8).'.'.$meta->extension;
        }

        return $name;
    }

    /**
     * Reduces any name to a single safe path segment carrying the given
     * extension — no directory separators, no leading dots, no surprises.
     */
    private function sanitizeName(string $name, string $extension): string
    {
        $stem = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo(basename($name), PATHINFO_FILENAME));
        $stem = trim($stem, '.-');

        if ($stem === '') {
            $stem = 'file';
        }

        return Str::limit($stem, 100, '').'.'.strtolower($extension);
    }

    private function uniquePath(FilesystemAdapter $disk, string $relative): string
    {
        if (! $disk->exists($relative)) {
            return $relative;
        }

        $dir       = \dirname($relative);
        $dir       = $dir === '.' ? '' : $dir.'/';
        $extension = pathinfo($relative, PATHINFO_EXTENSION);
        $stem      = pathinfo($relative, PATHINFO_FILENAME);

        return $dir.$stem.'-'.Str::lower(Str::random(6)).($extension === '' ? '' : '.'.$extension);
    }

    /**
     * Writes at most `$limit` bytes and reports how many arrived: one byte over
     * the limit is enough for the caller to reject the chunk, so an oversized
     * body never lands on disk in full.
     *
     * @param resource|string $content
     */
    private function writeCapped(mixed $content, string $path, int $limit): int
    {
        File::ensureDirectoryExists(\dirname($path));

        $out = fopen($path, 'wb');

        if ($out === false) {
            throw ChunkUploadException::assemblyFailed('the chunk file could not be created');
        }

        $written = 0;

        try {
            if (\is_resource($content)) {
                while (! feof($content)) {
                    $buffer = fread($content, self::COPY_BUFFER);

                    if ($buffer === false || $buffer === '') {
                        break;
                    }

                    $written += \strlen($buffer);

                    if ($written > $limit) {
                        return $written;
                    }

                    fwrite($out, $buffer);
                }

                return $written;
            }

            $written = \strlen((string) $content);

            if ($written <= $limit) {
                fwrite($out, (string) $content);
            }

            return $written;
        } finally {
            fclose($out);
        }
    }
}

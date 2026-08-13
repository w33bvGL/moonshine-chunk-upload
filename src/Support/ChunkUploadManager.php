<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Support;

use Closure;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;
use W33bvgl\MoonShineChunkUpload\Events\ChunkUploadCompleted;
use W33bvgl\MoonShineChunkUpload\Exceptions\ChunkUploadException;

final readonly class ChunkUploadManager
{
    private const int COPY_BUFFER_BYTES = 1_048_576;

    private const int NAME_LENGTH_LIMIT = 100;

    private const string PART_SUFFIX = '.part';

    private const string ASSEMBLING_SUFFIX = '.assembling';

    private const string META_FILE = 'meta.json';

    public function __construct(private ChunkUploadConfig $config) {}

    public function config(): ChunkUploadConfig
    {
        return $this->config;
    }

    public function disk(): FilesystemAdapter
    {
        $disk = Storage::disk($this->config->disk);

        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw ChunkUploadException::unsupportedDisk($this->config->disk);
        }

        return $disk;
    }

    public function start(
        string $filename,
        int $size,
        int $total,
        int $chunkSize,
        string $profile,
        bool $keepOriginalName = false,
    ): string {
        $extensions = $this->config->extensionsFor($profile);

        if ($extensions === []) {
            throw ChunkUploadException::unknownProfile($profile);
        }

        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

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
            createdAt: Date::now()->toIso8601String(),
        );

        $stored = $this->disk()->put(
            $this->metaPath($uploadId),
            json_encode($meta, JSON_THROW_ON_ERROR),
        );

        if ($stored === false) {
            throw ChunkUploadException::assemblyFailed('the upload could not be registered');
        }

        return $uploadId;
    }

    /**
     * @param resource|string $content
     */
    public function receiveChunk(string $uploadId, int $index, mixed $content): int
    {
        $meta = $this->meta($uploadId) ?? throw ChunkUploadException::notFound();

        if ($index < 1 || $index > $meta->total) {
            throw ChunkUploadException::chunkOutOfRange($index);
        }

        $expected = $meta->expectedChunkBytes($index);
        $part     = $this->disk()->path($this->partPath($uploadId, $index));
        $staged   = $part.'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $written = $this->writeCapped($content, $staged, $expected);

            if ($written !== $expected) {
                throw ChunkUploadException::invalidChunkSize($index);
            }

            if (! @rename($staged, $part)) {
                throw ChunkUploadException::assemblyFailed("chunk {$index} could not be stored");
            }
        } catch (Throwable $e) {
            File::delete($staged);

            throw $e;
        }

        return $written;
    }

    /**
     * @return list<int>
     */
    public function receivedIndexes(string $uploadId): array
    {
        $pattern = '#(?:^|/)(\d+)'.preg_quote(self::PART_SUFFIX, '#').'$#';
        $indexes = [];

        foreach ($this->disk()->files($this->config->tmpDirFor($uploadId)) as $file) {
            if (preg_match($pattern, $file, $matches) === 1) {
                $indexes[] = (int) $matches[1];
            }
        }

        sort($indexes);

        return $indexes;
    }

    public function finalize(string $uploadId): string
    {
        $meta = $this->meta($uploadId) ?? throw ChunkUploadException::notFound();

        $missing = array_values(array_diff(range(1, $meta->total), $this->receivedIndexes($uploadId)));

        if ($missing !== []) {
            throw ChunkUploadException::incompleteUpload($missing);
        }

        $assembling = $this->config->tmpDirFor($uploadId).self::ASSEMBLING_SUFFIX;

        if (! @rename(
            $this->disk()->path($this->config->tmpDirFor($uploadId)),
            $this->disk()->path($assembling),
        )) {
            throw ChunkUploadException::alreadyAssembling();
        }

        try {
            $path = $this->assemble($assembling, $uploadId, $meta);
        } finally {
            $this->disk()->deleteDirectory($assembling);
        }

        event(new ChunkUploadCompleted($uploadId, $path, $meta));

        return $path;
    }

    public function abort(string $uploadId): void
    {
        $this->disk()->deleteDirectory($this->config->tmpDirFor($uploadId));
    }

    public function meta(string $uploadId): ?UploadMeta
    {
        $raw = rescue(fn (): ?string => $this->disk()->get($this->metaPath($uploadId)), report: false);

        if (! \is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true, flags: JSON_INVALID_UTF8_SUBSTITUTE);

        return \is_array($decoded) ? UploadMeta::fromArray($decoded) : null;
    }

    public function isFinalizedPath(string $path): bool
    {
        $prefix = $this->config->finalDir.'/';

        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', substr($path, \strlen($prefix))) === 1;
    }

    /**
     * @param null|Closure(string): string $rename
     */
    public function claim(string $path, string $toDisk, string $toDir = '', ?Closure $rename = null): ?string
    {
        if (! $this->isFinalizedPath($path) || ! $this->disk()->exists($path)) {
            return null;
        }

        $name = basename($path);

        if ($rename instanceof Closure) {
            $name = $this->sanitizeName($rename($name), pathinfo($name, PATHINFO_EXTENSION));
        }

        $dir      = trim($toDir, '/');
        $target   = Storage::disk($toDisk);
        $relative = $this->uniquePath($target, ($dir === '' ? '' : $dir.'/').$name);

        if ($toDisk === $this->config->disk) {
            return $this->disk()->move($path, $relative) ? $relative : null;
        }

        $stream = $this->disk()->readStream($path);

        if (! \is_resource($stream)) {
            return null;
        }

        try {
            $stored = $target->writeStream($relative, $stream);
        } finally {
            fclose($stream);
        }

        if ($stored === false) {
            return null;
        }

        $this->disk()->delete($path);

        return $relative;
    }

    public function pruneTmp(?int $hours = null, bool $dryRun = false): int
    {
        return $this->prune(
            $this->disk()->directories($this->config->tmpDir),
            $hours ?? $this->config->tmpTtlHours,
            $dryRun,
            fn (string $directory): bool => $this->disk()->deleteDirectory($directory),
        );
    }

    public function pruneFinal(?int $hours = null, bool $dryRun = false): int
    {
        return $this->prune(
            $this->disk()->files($this->config->finalDir),
            $hours ?? $this->config->finalTtlHours,
            $dryRun,
            fn (string $file): bool => $this->disk()->delete($file),
        );
    }

    /**
     * @param iterable<array-key, string> $paths
     * @param Closure(string): bool       $delete
     */
    private function prune(iterable $paths, int $hours, bool $dryRun, Closure $delete): int
    {
        $threshold = Date::now()->subHours(max(0, $hours));
        $pruned    = 0;

        foreach ($paths as $path) {
            $modifiedAt = @filemtime($this->disk()->path($path));

            if ($modifiedAt === false) {
                continue;
            }

            if (Date::createFromTimestamp($modifiedAt)->isAfter($threshold)) {
                continue;
            }

            if (! $dryRun) {
                $delete($path);
            }

            $pruned++;
        }

        return $pruned;
    }

    private function assemble(string $directory, string $uploadId, UploadMeta $meta): string
    {
        [$path, $out] = $this->createFinalFile($uploadId, $meta);

        try {
            $written = 0;

            for ($index = 1; $index <= $meta->total; $index++) {
                $in = @fopen($this->disk()->path("{$directory}/{$index}".self::PART_SUFFIX), 'rb');

                if ($in === false) {
                    throw ChunkUploadException::assemblyFailed("chunk {$index} could not be read");
                }

                try {
                    $copied = stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                }

                if ($copied !== $meta->expectedChunkBytes($index)) {
                    throw ChunkUploadException::assemblyFailed("chunk {$index} could not be copied in full");
                }

                $written += $copied;
            }

            if ($written !== $meta->size) {
                throw ChunkUploadException::sizeMismatch();
            }
        } catch (Throwable $e) {
            fclose($out);
            $this->disk()->delete($path);

            throw $e;
        }

        fclose($out);

        return $path;
    }

    /**
     * @return array{0: string, 1: resource}
     */
    private function createFinalFile(string $uploadId, UploadMeta $meta): array
    {
        foreach ($this->finalNameCandidates($uploadId, $meta) as $name) {
            $path     = $this->config->finalPathFor($name);
            $absolute = $this->disk()->path($path);

            File::ensureDirectoryExists(\dirname($absolute));

            $handle = @fopen($absolute, 'xb');

            if ($handle !== false) {
                return [$path, $handle];
            }
        }

        throw ChunkUploadException::assemblyFailed('the destination file could not be created');
    }

    /**
     * @return Generator<int, string>
     */
    private function finalNameCandidates(string $uploadId, UploadMeta $meta): Generator
    {
        if (! $meta->keepOriginalName) {
            yield "{$uploadId}.{$meta->extension}";
        } else {
            $name = $this->sanitizeName($meta->filename, $meta->extension);
            $stem = pathinfo($name, PATHINFO_FILENAME);

            yield $name;

            yield "{$stem}-".mb_substr($uploadId, 0, 8).".{$meta->extension}";
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            yield "{$uploadId}-".Str::lower(Str::random(8)).".{$meta->extension}";
        }
    }

    private function sanitizeName(string $name, string $extension): string
    {
        $stem = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo(basename($name), PATHINFO_FILENAME));
        $stem = trim($stem, '.-');

        return Str::limit($stem === '' ? 'file' : $stem, self::NAME_LENGTH_LIMIT, '').'.'.mb_strtolower($extension);
    }

    private function uniquePath(Filesystem $disk, string $relative): string
    {
        if (! $disk->exists($relative)) {
            return $relative;
        }

        $directory = \dirname($relative);
        $directory = $directory === '.' ? '' : $directory.'/';
        $extension = pathinfo($relative, PATHINFO_EXTENSION);
        $stem      = pathinfo($relative, PATHINFO_FILENAME);

        return $directory.$stem.'-'.Str::lower(Str::random(6)).($extension === '' ? '' : '.'.$extension);
    }

    /**
     * @param resource|string $content
     */
    private function writeCapped(mixed $content, string $path, int $limit): int
    {
        File::ensureDirectoryExists(\dirname($path));

        $out = @fopen($path, 'wb');

        if ($out === false) {
            throw ChunkUploadException::assemblyFailed('the chunk file could not be created');
        }

        try {
            if (! \is_resource($content)) {
                $body    = (string) $content;
                $written = \strlen($body);

                if ($written <= $limit) {
                    $this->write($out, $body);
                }

                return $written;
            }

            $written = 0;

            while (! feof($content)) {
                $buffer = fread($content, self::COPY_BUFFER_BYTES);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                $written += \strlen($buffer);

                if ($written > $limit) {
                    return $written;
                }

                $this->write($out, $buffer);
            }

            return $written;
        } finally {
            fclose($out);
        }
    }

    /**
     * @param resource $handle
     */
    private function write(mixed $handle, string $buffer): void
    {
        if (fwrite($handle, $buffer) !== \strlen($buffer)) {
            throw ChunkUploadException::assemblyFailed('the chunk could not be written to disk');
        }
    }

    private function metaPath(string $uploadId): string
    {
        return $this->config->tmpDirFor($uploadId).'/'.self::META_FILE;
    }

    private function partPath(string $uploadId, int $index): string
    {
        return $this->config->tmpDirFor($uploadId)."/{$index}".self::PART_SUFFIX;
    }
}

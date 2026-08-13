<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Support;

use Illuminate\Contracts\Config\Repository;

final readonly class ChunkUploadConfig
{
    public const string KEY = 'moonshine-chunk-upload';

    /**
     * @param array<string, list<string>> $profiles
     * @param list<string>                $routeMiddleware
     */
    public function __construct(
        public string $disk,
        public string $tmpDir,
        public string $finalDir,
        public int $maxChunkSize,
        public int $maxFileSize,
        public int $tmpTtlHours,
        public int $finalTtlHours,
        public string $routePrefix,
        public string $routeName,
        public array $routeMiddleware,
        public array $profiles,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            disk: (string) $config->get(self::KEY.'.disk', 'local'),
            tmpDir: self::normalizeDir($config->get(self::KEY.'.tmp_dir'), 'chunked-uploads/tmp'),
            finalDir: self::normalizeDir($config->get(self::KEY.'.final_dir'), 'chunked-uploads/final'),
            maxChunkSize: self::positiveInt($config->get(self::KEY.'.max_chunk_size'), 16 * 1024 * 1024),
            maxFileSize: self::positiveInt($config->get(self::KEY.'.max_file_size'), 32 * 1024 * 1024 * 1024),
            tmpTtlHours: self::positiveInt($config->get(self::KEY.'.tmp_ttl_hours'), 24),
            finalTtlHours: self::positiveInt($config->get(self::KEY.'.final_ttl_hours'), 72),
            routePrefix: (string) $config->get(self::KEY.'.route.prefix', self::KEY),
            routeName: (string) $config->get(self::KEY.'.route.name', self::KEY.'.'),
            routeMiddleware: self::normalizeMiddleware($config->get(self::KEY.'.route.middleware', [])),
            profiles: self::normalizeProfiles($config->get(self::KEY.'.profiles', [])),
        );
    }

    /**
     * @return list<string>
     */
    public function extensionsFor(string $profile): array
    {
        return $this->profiles[$profile] ?? [];
    }

    /**
     * @return list<string>
     */
    public function profileNames(): array
    {
        return array_keys($this->profiles);
    }

    public function maxChunks(): int
    {
        return (int) ceil($this->maxFileSize / $this->maxChunkSize);
    }

    public function tmpDirFor(string $uploadId): string
    {
        return "{$this->tmpDir}/{$uploadId}";
    }

    public function finalPathFor(string $name): string
    {
        return "{$this->finalDir}/{$name}";
    }

    public function routeName(string $action): string
    {
        return $this->routeName.$action;
    }

    private static function normalizeDir(mixed $value, string $default): string
    {
        $dir = trim(\is_string($value) ? $value : '', '/');

        return $dir === '' ? $default : $dir;
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        $int = is_numeric($value) ? (int) $value : $default;

        return max(1, $int);
    }

    /**
     * @return list<string>
     */
    private static function normalizeMiddleware(mixed $middleware): array
    {
        $list = \is_array($middleware) ? $middleware : [$middleware];

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => \is_string($item) ? $item : '', $list),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function normalizeProfiles(mixed $profiles): array
    {
        $normalized = [];

        foreach (\is_array($profiles) ? $profiles : [] as $profile => $extensions) {
            $normalized[(string) $profile] = array_values(array_unique(array_map(
                static fn (mixed $extension): string => mb_strtolower(trim((string) $extension, '.')),
                \is_array($extensions) ? $extensions : [],
            )));
        }

        return $normalized;
    }
}

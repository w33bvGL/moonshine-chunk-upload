<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Fields;

use Closure;
use Illuminate\Contracts\Support\Renderable;
use MoonShine\AssetManager\Js;
use MoonShine\UI\Components\Link;
use MoonShine\UI\Contracts\FileableContract;
use MoonShine\UI\Contracts\RemovableContract;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Traits\Removable;
use MoonShine\UI\Traits\WithStorage;
use Override;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadConfig;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

/**
 * @method static static make(Closure|string|null $label = null, ?string $column = null, ?Closure $formatted = null)
 */
class ChunkUpload extends Field implements FileableContract, RemovableContract
{
    use Removable;
    use WithStorage;

    protected const int MIN_CONCURRENCY = 1;

    protected const int MAX_CONCURRENCY = 8;

    protected string $view = 'moonshine-chunk-upload::fields.chunk-upload';

    protected string $profile = 'video';

    /** @var list<string> */
    protected array $allowedExtensions = [];

    protected string $color = 'primary';

    protected int $chunkSize = 8 * 1024 * 1024;

    protected int $concurrency = 4;

    protected bool $debug = false;

    protected bool $keepOriginalFileName = false;

    protected bool $isDeleteFiles = true;

    protected bool $disableDownload = false;

    /** @var null|Closure(string, static): string */
    protected ?Closure $customName = null;

    protected ?string $title = null;

    protected ?string $btnText = null;

    protected string $icon = 'c.cloud-arrow-up';

    public function profile(string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * @param list<string> $allowedExtensions
     */
    public function allowedExtensions(array $allowedExtensions): static
    {
        $this->allowedExtensions = array_values(array_map(
            static fn (string $extension): string => mb_strtolower(trim($extension, '.')),
            $allowedExtensions,
        ));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getAllowedExtensions(): array
    {
        return $this->allowedExtensions === []
            ? $this->config()->extensionsFor($this->profile)
            : $this->allowedExtensions;
    }

    public function isAllowedExtension(string $extension): bool
    {
        $extensions = $this->getAllowedExtensions();

        return $extensions === [] || \in_array(mb_strtolower($extension), $extensions, true);
    }

    public function chunkSize(int $bytes): static
    {
        $this->chunkSize = max(1, $bytes);

        return $this;
    }

    public function getChunkSize(): int
    {
        return min($this->chunkSize, $this->config()->maxChunkSize);
    }

    public function concurrency(int $parallelRequests): static
    {
        $this->concurrency = max(self::MIN_CONCURRENCY, min(self::MAX_CONCURRENCY, $parallelRequests));

        return $this;
    }

    public function getConcurrency(): int
    {
        return $this->concurrency;
    }

    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function btnText(string $text): static
    {
        $this->btnText = $text;

        return $this;
    }

    public function debug(bool $debug = true): static
    {
        $this->debug = $debug;

        return $this;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function keepOriginalFileName(): static
    {
        $this->keepOriginalFileName = true;

        return $this;
    }

    public function isKeepOriginalFileName(): bool
    {
        return $this->keepOriginalFileName;
    }

    /**
     * @param Closure(string $name, static $ctx): string $name
     */
    public function customName(Closure $name): static
    {
        $this->customName = $name;

        return $this;
    }

    /**
     * @return null|Closure(string $name, static $ctx): string
     */
    public function getCustomName(): ?Closure
    {
        return $this->customName;
    }

    public function disableDeleteFiles(): static
    {
        $this->isDeleteFiles = false;

        return $this;
    }

    public function isDeleteFiles(): bool
    {
        return $this->isDeleteFiles;
    }

    public function disableDownload(Closure|bool|null $condition = null): static
    {
        $this->disableDownload = (bool) (value($condition, $this) ?? true);

        return $this;
    }

    public function canDownload(): bool
    {
        return ! $this->disableDownload;
    }

    #[Override]
    protected function assets(): array
    {
        return [
            Js::make('vendor/moonshine-chunk-upload/chunk-upload.js')->defer(),
        ];
    }

    #[Override]
    protected function resolveValue(): mixed
    {
        return $this->toValue();
    }

    #[Override]
    protected function resolvePreview(): Renderable|string
    {
        $value = $this->stringValue();

        if ($value === '') {
            return '';
        }

        $name = basename($value);

        return $this->canDownload()
            ? Link::make($this->getStorageUrl($value), $name)->blank()->render()
            : $name;
    }

    #[Override]
    protected function resolveOnApply(): ?Closure
    {
        return function (mixed $item, mixed $value = null): mixed {
            if ($value === false || $value === null) {
                return $item;
            }

            $new = \is_string($value) ? trim($value) : '';
            $old = data_get($item, $this->getColumn());
            $old = \is_string($old) ? $old : '';

            if ($new === $old) {
                return $item;
            }

            if ($new === '') {
                $this->deleteFile($old);

                return data_set($item, $this->getColumn(), null);
            }

            $stored = $this->manager()->claim(
                path: $new,
                toDisk: $this->getDisk(),
                toDir: $this->getDir(),
                rename: $this->customName instanceof Closure
                    ? fn (string $name): string => \call_user_func($this->customName, $name, $this)
                    : null,
            );

            if ($stored === null) {
                return $item;
            }

            $this->deleteFile($old);

            return data_set($item, $this->getColumn(), $stored);
        };
    }

    #[Override]
    protected function resolveAfterDestroy(mixed $data): mixed
    {
        $this->deleteFile($this->stringValue());

        return $data;
    }

    #[Override]
    protected function viewData(): array
    {
        $value      = $this->stringValue();
        $extensions = $this->getAllowedExtensions();

        return [
            'inputName' => $this->getNameAttribute(),
            'color' => $this->color,
            'icon' => $this->icon,
            'title' => $this->title ?? (string) __('moonshine-chunk-upload::ui.title'),
            'btnText' => $this->btnText ?? (string) __('moonshine-chunk-upload::ui.choose'),
            'accept' => $extensions === [] ? '*' : '.'.implode(',.', $extensions),
            'isDebug' => $this->isDebug(),
            'isRemovable' => $this->isRemovable(),
            'previewUrl' => $value !== '' && $this->canDownload() ? $this->getStorageUrl($value) : null,
            'uploader' => $this->uploaderConfig($value, $extensions),
        ];
    }

    /**
     * @param  list<string>         $extensions
     * @return array<string, mixed>
     */
    protected function uploaderConfig(string $value, array $extensions): array
    {
        $config = $this->config();

        return [
            'urls' => [
                'init' => route($config->routeName('init')),
                'chunk' => route($config->routeName('chunk')),
                'status' => route($config->routeName('status')),
                'finalize' => route($config->routeName('finalize')),
                'abort' => route($config->routeName('abort')),
            ],
            'csrfToken' => csrf_token(),
            'storageKey' => $this->storageKey(),
            'initialValue' => $value,
            'profile' => $this->profile,
            'extensions' => $extensions,
            'chunkSize' => $this->getChunkSize(),
            'maxFileSize' => $config->maxFileSize,
            'concurrency' => $this->getConcurrency(),
            'keepName' => $this->isKeepOriginalFileName(),
            'labels' => [
                'too_large' => (string) __('moonshine-chunk-upload::ui.too_large'),
                'bad_extension' => (string) __('moonshine-chunk-upload::ui.bad_extension'),
                'network_error' => (string) __('moonshine-chunk-upload::ui.network_error'),
                'server_error' => (string) __('moonshine-chunk-upload::ui.server_error'),
                'resumable' => (string) __('moonshine-chunk-upload::ui.resumable'),
            ],
        ];
    }

    protected function storageKey(): string
    {
        return 'moonshine-chunk-upload:'.sha1(request()->path().'|'.$this->getNameAttribute());
    }

    protected function deleteFile(string $value): void
    {
        if ($value === '' || ! $this->isDeleteFiles()) {
            return;
        }

        rescue(fn (): bool => $this->deleteStorageFile($value), report: false);
    }

    protected function stringValue(): string
    {
        $value = $this->toValue();

        return \is_string($value) ? $value : '';
    }

    protected function manager(): ChunkUploadManager
    {
        return resolve(ChunkUploadManager::class);
    }

    protected function config(): ChunkUploadConfig
    {
        return resolve(ChunkUploadConfig::class);
    }
}

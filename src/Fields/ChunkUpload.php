<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Fields;

use Closure;
use Illuminate\Contracts\Support\Renderable;
use MoonShine\AssetManager\Js;
use MoonShine\UI\Components\Link;
use MoonShine\UI\Contracts\RemovableContract;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Traits\Removable;
use MoonShine\UI\Traits\WithStorage;
use Override;
use W33bvgl\MoonShineChunkUpload\Support\ChunkUploadManager;

/**
 * A MoonShine field that uploads its file in parallel, resumable chunks.
 *
 * The browser talks to the package's own endpoints, which assemble the parts in
 * a staging directory; the form itself only carries the resulting path. On
 * apply, the field claims that file and moves it onto its own disk/dir — a
 * submitted value that is not a finalized upload of this package is discarded,
 * so the hidden input cannot be pointed at an arbitrary file.
 *
 * @method static static make(Closure|string|null $label = null, ?string $column = null, ?Closure $formatted = null)
 */
class ChunkUpload extends Field implements RemovableContract
{
    use Removable;
    use WithStorage;

    protected string $view = 'moonshine-chunk-upload::fields.chunk-upload';

    protected string $profile = 'video';

    /** @var ?list<string> */
    protected ?array $allowedExtensionsOverride = null;

    protected string $color = 'primary';

    protected int $chunkSize = 8 * 1024 * 1024;

    protected int $concurrency = 4;

    protected bool $debug = false;

    protected bool $keepOriginalFileName = false;

    protected bool $deleteFiles = true;

    /** @var null|Closure(string, static): string */
    protected ?Closure $customName = null;

    protected ?string $title = null;

    protected string $icon = 'c.cloud-arrow-up';

    protected ?string $btnText = null;

    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Bytes per chunk. Must not exceed `moonshine-chunk-upload.max_chunk_size`,
     * and should stay under the web server's request body limit.
     */
    public function chunkSize(int $bytes): static
    {
        $this->chunkSize = max(1, $bytes);

        return $this;
    }

    /**
     * How many chunks may be in flight at once (1–8).
     */
    public function concurrency(int $parallelRequests): static
    {
        $this->concurrency = max(1, min(8, $parallelRequests));

        return $this;
    }

    /**
     * Extension profile from config/moonshine-chunk-upload.php (video / audio / subtitle / ...).
     */
    public function profile(string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * Restrict uploads to a specific extension list, overriding the profile default.
     *
     * @param list<string> $extensions
     */
    public function allowedExtensions(array $extensions): static
    {
        $this->allowedExtensionsOverride = array_values(array_map('strtolower', $extensions));

        return $this;
    }

    /**
     * Store the file under a sanitized version of the name it had on the client
     * instead of the upload id.
     */
    public function keepOriginalFileName(): static
    {
        $this->keepOriginalFileName = true;

        return $this;
    }

    /**
     * Rename the assembled file while it is moved onto this field's disk.
     *
     * @param Closure(string $name, static $ctx): string $callback
     */
    public function customName(Closure $callback): static
    {
        $this->customName = $callback;

        return $this;
    }

    /**
     * Keep the previously stored file on disk when the value is replaced or the
     * record is deleted.
     */
    public function disableDeleteFiles(): static
    {
        $this->deleteFiles = false;

        return $this;
    }

    public function isDeleteFiles(): bool
    {
        return $this->deleteFiles;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function btnText(string $text): static
    {
        $this->btnText = $text;

        return $this;
    }

    /**
     * Render the per-chunk request log under the field.
     */
    public function debug(bool $debug = true): static
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getAllowedExtensions(): array
    {
        return $this->allowedExtensionsOverride
            ?? $this->manager()->config()->extensionsFor($this->profile);
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
        $value = $this->toValue();

        if (! \is_string($value) || $value === '') {
            return '';
        }

        return Link::make($this->getStorageUrl($value), basename($value))
            ->blank()
            ->render();
    }

    /**
     * Claims the finalized upload and stores its new path. A value that is
     * neither the current one nor a finalized upload is ignored outright.
     */
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
                $this->deleteStoredFile($old);

                return data_set($item, $this->getColumn(), null);
            }

            $stored = $this->manager()->claim(
                path: $new,
                toDisk: $this->getDisk(),
                toDir: $this->getDir(),
                rename: $this->customName === null
                    ? null
                    : fn (string $name): string => (string) \call_user_func($this->customName, $name, $this),
            );

            if ($stored === null) {
                return $item;
            }

            $this->deleteStoredFile($old);

            return data_set($item, $this->getColumn(), $stored);
        };
    }

    #[Override]
    protected function resolveAfterDestroy(mixed $data): mixed
    {
        $value = $this->toValue();

        if (\is_string($value)) {
            $this->deleteStoredFile($value);
        }

        return $data;
    }

    #[Override]
    protected function viewData(): array
    {
        $extensions = $this->getAllowedExtensions();
        $config     = $this->manager()->config();
        $routeName  = (string) config('moonshine-chunk-upload.route.name');

        return [
            'element' => $this,
            'inputName' => $this->getAttribute('name') ?? $this->getColumn(),
            'inputValue' => $this->toValue(),
            'csrfToken' => csrf_token(),
            'urls' => [
                'init' => route("{$routeName}init"),
                'chunk' => route("{$routeName}chunk"),
                'status' => route("{$routeName}status"),
                'finalize' => route("{$routeName}finalize"),
                'abort' => route("{$routeName}abort"),
            ],
            'profile' => $this->profile,
            'extensions' => $extensions,
            'chunkSize' => min($this->chunkSize, $config->maxChunkSize),
            'maxFileSize' => $config->maxFileSize,
            'concurrency' => $this->concurrency,
            'keepName' => $this->keepOriginalFileName,
            'color' => $this->color,
            'title' => $this->title ?? (string) __('moonshine-chunk-upload::ui.title'),
            'icon' => $this->icon,
            'btnText' => $this->btnText ?? (string) __('moonshine-chunk-upload::ui.choose'),
            'debug' => $this->debug,
            'isRemovable' => $this->isRemovable(),
            'previewUrl' => \is_string($this->toValue()) && $this->toValue() !== ''
                ? $this->getStorageUrl((string) $this->toValue())
                : null,
            'accept' => $extensions === []
                ? '*'
                : '.'.implode(',.', $extensions),
        ];
    }

    private function deleteStoredFile(string $value): void
    {
        if ($value === '' || ! $this->isDeleteFiles()) {
            return;
        }

        rescue(fn (): bool => $this->deleteStorageFile($value), report: false);
    }

    private function manager(): ChunkUploadManager
    {
        return app(ChunkUploadManager::class);
    }
}

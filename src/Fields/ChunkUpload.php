<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace W33bvgl\MoonShineChunkUpload\Fields;

use MoonShine\AssetManager\Js;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Traits\Removable;
use Override;

class ChunkUpload extends Field
{
    use Removable;

    protected string $view = 'moonshine-chunk-upload::fields.chunk-upload';

    protected string $profile = 'video';

    /** @var ?list<string> */
    protected ?array $allowedExtensionsOverride = null;

    protected string $disk = 'public';

    protected string $color = 'primary';

    protected int $chunkSize = 8 * 1024 * 1024;

    protected int $concurrency = 4;

    protected bool $debug = false;

    protected string $title = 'Upload file';

    protected string $icon = 'c.cloud-arrow-up';

    protected string $btnText = 'Choose file';

    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function color(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function chunkSize(int $bytes): self
    {
        $this->chunkSize = $bytes;

        return $this;
    }

    public function concurrency(int $parallelRequests): self
    {
        $this->concurrency = max(1, min(8, $parallelRequests));

        return $this;
    }

    /**
     * Extension profile from config/moonshine-chunk-upload.php (video / audio / subtitle / ...).
     */
    public function profile(string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * Restrict uploads to a specific extension list, overriding the profile default.
     *
     * @param list<string> $extensions
     */
    public function allowedExtensions(array $extensions): self
    {
        $this->allowedExtensionsOverride = $extensions;

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function btnText(string $text): self
    {
        $this->btnText = $text;

        return $this;
    }

    public function debug(bool $debug = true): self
    {
        $this->debug = $debug;

        return $this;
    }

    #[Override]
    protected function assets(): array
    {
        return [
            Js::make('vendor/moonshine-chunk-upload/chunk-upload.js')->defer(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function getAllowedExtensions(): array
    {
        if ($this->allowedExtensionsOverride !== null) {
            return $this->allowedExtensionsOverride;
        }

        /** @var array<string, list<string>> $profiles */
        $profiles = config('moonshine-chunk-upload.profiles');

        return $profiles[$this->profile] ?? [];
    }

    #[Override]
    protected function resolveValue(): mixed
    {
        return $this->toValue();
    }

    #[Override]
    protected function viewData(): array
    {
        $extensions = $this->getAllowedExtensions();
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
            'chunkSize' => $this->chunkSize,
            'concurrency' => $this->concurrency,
            'color' => $this->color,
            'title' => $this->title,
            'icon' => $this->icon,
            'btnText' => $this->btnText,
            'debug' => $this->debug,
            'accept' => $extensions === []
                ? '*'
                : '.'.implode(',.', $extensions),
        ];
    }
}

<?php

declare(strict_types=1);

namespace W33bvgl\MoonShineChunkUpload\Fields;

use Closure;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Traits\Removable;

class ChunkUpload extends Field
{
    use Removable;

    protected string $view = 'moonshine-chunk-upload::fields.chunk-upload';

    protected string $uploadRoute = '';
    protected array $allowedExtensions = [];
    protected string $disk = 'public';

    protected string $color = 'primary';
    protected string $size = 'md';
    protected bool $radial = false;

    public function __construct(string $label, ?string $column = null, ?Closure $formattedValueCallback = null)
    {
        parent::__construct($label, $column, $formattedValueCallback);

        $this->uploadRoute = route('moonshine-chunk.upload');
    }

    public function video(): self
    {
        return $this->allowedExtensions(['mp4', 'mov', 'avi', 'mkv', 'webm']);
    }

    public function file(): self
    {
        return $this->allowedExtensions(['zip', 'rar', '7z', 'pdf', 'docx']);
    }

    public function color(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function size(string $size): self
    {
        $this->size = $size;
        return $this;
    }

    public function radial(): self
    {
        $this->radial = true;
        return $this;
    }

    public function disk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    public function uploadRoute(string $route): self
    {
        $this->uploadRoute = $route;
        return $this;
    }

    public function allowedExtensions(array $extensions): self
    {
        $this->allowedExtensions = array_map(
            fn($ext) => str_replace('.', '', strtolower($ext)),
            $extensions
        );

        return $this;
    }

    protected function resolveValue(): mixed
    {
        return $this->toValue();
    }

    protected function viewData(): array
    {
        $accept = !empty($this->allowedExtensions)
            ? '.' . implode(',.', $this->allowedExtensions)
            : '*';

        return [
            'element' => $this,
            'inputName' => $this->getColumn(),
            'inputValue' => $this->toValue(),
            'uploadRoute' => $this->uploadRoute,
            'extensions' => $this->allowedExtensions,
            'accept' => $accept,
            'color' => $this->color,
            'size' => $this->size,
            'radial' => $this->radial,
            'progressAttributes' => [
                'x-bind:value' => 'progress',
            ]
        ];
    }
}
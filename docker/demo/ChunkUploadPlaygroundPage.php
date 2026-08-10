<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace App\MoonShine\Pages;

use App\MoonShine\Layouts\PlaygroundLayout;
use Illuminate\Support\Facades\Storage;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Div;
use MoonShine\UI\Components\Layout\Grid;
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

/**
 * The sandbox page: three fields covering the options that behave differently
 * at runtime, and a live view of what ended up on disk.
 */
class ChunkUploadPlaygroundPage extends Page
{
    protected ?string $layout = PlaygroundLayout::class;

    protected function assets(): array
    {
        return [
            ...ChunkUpload::make('Video', 'video')->getAssets(),
        ];
    }

    public function getTitle(): string
    {
        return 'w33bvgl/moonshine-chunk-upload — playground';
    }

    public function components(): array
    {
        return [
            Div::make([
                Grid::make([
                    Column::make([
                        Box::make('Upload', [
                            FlexibleRender::make(
                                '<p class="text-sm text-secondary mb-4">'
                                .'Каждое поле режет файл на чанки и грузит их параллельно. '
                                .'Отключите сеть на середине — загрузка встанет на ошибке и продолжится '
                                .'с последнего принятого чанка; перезагрузите страницу — поле предложит '
                                .'дозалить тот же файл.</p>',
                            ),
                            FormBuilder::make(route('playground.apply'))
                                ->fields([
                                    ChunkUpload::make('Видео (2 МБ чанки, debug)', 'video')
                                        ->profile('video')
                                        ->disk('public')
                                        ->dir('videos')
                                        ->chunkSize(2 * 1024 * 1024)
                                        ->concurrency(4)
                                        ->removable()
                                        ->debug(),
                                    ChunkUpload::make('Аудио (исходное имя файла)', 'audio')
                                        ->profile('audio')
                                        ->disk('public')
                                        ->dir('audio')
                                        ->chunkSize(1024 * 1024)
                                        ->concurrency(2)
                                        ->keepOriginalFileName(),
                                    ChunkUpload::make('Субтитры (один поток)', 'subtitle')
                                        ->profile('subtitle')
                                        ->disk('public')
                                        ->dir('subtitles')
                                        ->chunkSize(256 * 1024)
                                        ->concurrency(1),
                                ])
                                ->submit('Сохранить'),
                        ]),
                    ])->columnSpan(7),

                    Column::make([
                        Box::make('Что лежит на диске', [
                            FlexibleRender::make($this->diskReport()),
                        ]),
                    ])->columnSpan(5),
                ]),
            ]),
        ];
    }

    private function diskReport(): string
    {
        $html = '';

        if (session()->has('playground.applied')) {
            $html .= '<div class="mb-4 text-sm"><b>Последняя отправка формы:</b><pre class="mt-2 p-3 rounded bg-black text-green-400 text-xs overflow-x-auto">'
                .e((string) json_encode(session('playground.applied'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                .'</pre></div>';
        }

        $html .= $this->fileList('Загружено полями (disk public)', Storage::disk('public')->allFiles());
        $html .= $this->fileList(
            'Ждут отправки формы (staging)',
            Storage::disk(config('moonshine-chunk-upload.disk'))->allFiles(config('moonshine-chunk-upload.final_dir')),
        );
        $html .= $this->fileList(
            'Незавершённые загрузки (tmp)',
            Storage::disk(config('moonshine-chunk-upload.disk'))->directories(config('moonshine-chunk-upload.tmp_dir')),
        );

        return $html;
    }

    /**
     * @param list<string> $items
     */
    private function fileList(string $title, array $items): string
    {
        $rows = $items === []
            ? '<li class="text-secondary">—</li>'
            : implode('', array_map(
                static fn (string $item): string => '<li class="truncate">'.e($item).'</li>',
                $items,
            ));

        return '<div class="mb-4"><div class="text-xs font-bold uppercase tracking-widest text-secondary mb-2">'
            .e($title).'</div><ul class="text-xs space-y-1">'.$rows.'</ul></div>';
    }
}

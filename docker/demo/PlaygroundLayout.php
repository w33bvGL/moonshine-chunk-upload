<?php

declare(strict_types=1);

/*
 * Copyright Anidzen @w33bvgl
 */

namespace App\MoonShine\Layouts;

use MoonShine\ColorManager\Palettes\NeutralPalette;
use MoonShine\Contracts\ColorManager\PaletteContract;
use MoonShine\Laravel\Layouts\AppLayout;

final class PlaygroundLayout extends AppLayout
{
    protected bool $sidebar = false;

    /**
     * @var null|class-string<PaletteContract>
     */
    protected ?string $palette = NeutralPalette::class;
}

<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

enum BuildMode: string
{
    case Production = 'production';
    case Preview = 'preview';
}

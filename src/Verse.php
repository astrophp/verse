<?php

declare(strict_types=1);

namespace Astro\Verse;

final class Verse
{
    public const string VERSION = '0.2.0';

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Directory that holds the bundled themes (`themes/<name>/`).
     */
    public static function themesPath(): string
    {
        return self::packageRoot().'/themes';
    }
}

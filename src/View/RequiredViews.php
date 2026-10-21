<?php

declare(strict_types=1);

namespace Astro\Verse\View;

use Astro\Verse\Error\ThemeException;
use Astro\Verse\Theme\Theme;

/**
 * The views every theme must provide: the base layout and one view per role.
 */
final class RequiredViews
{
    /** @var list<string> */
    public const array NAMES = ['layouts/base', 'home', 'page', 'article', 'listing', 'tags', 'not-found'];

    /**
     * @throws ThemeException naming the first missing view
     */
    public static function assertPresent(Theme $theme): void
    {
        foreach (self::NAMES as $name) {
            if (! is_file($theme->viewsPath.'/'.$name.'.blade.php')) {
                throw new ThemeException(
                    'the theme is missing the required view "'.$name.'" (expected views/'.$name.'.blade.php).',
                    'themes/'.$theme->name,
                );
            }
        }
    }
}

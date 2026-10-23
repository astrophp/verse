<?php

declare(strict_types=1);

namespace Astro\Verse\View;

/**
 * The places where a website's `theme/hooks/<hook>.blade.php` is rendered into a theme's pages.
 */
enum Hook: string
{
    case Head = 'head';
    case BodyStart = 'body-start';
    case MainStart = 'main-start';
    case MainEnd = 'main-end';
    case BodyEnd = 'body-end';
    case ArticleBefore = 'article-before';
    case ArticleAfter = 'article-after';
    case PageBefore = 'page-before';
    case PageAfter = 'page-after';

    /** Site-relative folder of the hook views. */
    public const string DIRECTORY = 'theme/hooks';

    /**
     * Site-relative path of the hook view, e.g. `theme/hooks/body-start.blade.php`.
     */
    public function path(): string
    {
        return self::DIRECTORY.'/'.$this->value.'.blade.php';
    }

    /**
     * Every hook name in declaration order, for error messages.
     */
    public static function list(): string
    {
        return implode(', ', array_map(static fn (self $hook): string => $hook->value, self::cases()));
    }
}

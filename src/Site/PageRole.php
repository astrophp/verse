<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

/**
 * Semantic content role. Articles live in `content/articles/`, everything else is a standalone page.
 */
enum PageRole: string
{
    case Page = 'page';
    case Article = 'article';
}

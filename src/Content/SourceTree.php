<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

/**
 * The scanned `content/` folder.
 */
final readonly class SourceTree
{
    /**
     * @param  list<PageFile|PageBundle>  $articles  the Markdown files and bundles of `content/articles/`
     * @param  list<PageFile|PageBundle|PlainDir>  $children  everything else below `content/`
     */
    public function __construct(
        public array $articles,
        public array $children,
    ) {}
}

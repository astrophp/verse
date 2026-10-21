<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class TocEntry
{
    /**
     * @param  string  $id  the heading's id: `href="#id"` and `data-toc` are both this string
     */
    public function __construct(
        public string $id,
        public string $text,
    ) {}
}

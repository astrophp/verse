<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

/**
 * A standalone Markdown page (`name.md`).
 */
final readonly class PageFile
{
    /**
     * @param  string  $path  site-relative path, e.g. `content/about.md`
     * @param  list<string>  $segments  NFC route segments of the source location (empty for the home page)
     */
    public function __construct(
        public string $path,
        public array $segments,
    ) {}
}

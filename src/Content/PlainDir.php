<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

final readonly class PlainDir
{
    /**
     * @param  string  $directory  site-relative directory
     * @param  list<string>  $segments  NFC route segments of the directory
     * @param  list<PageFile|PageBundle|PlainDir>  $children
     */
    public function __construct(
        public string $directory,
        public array $segments,
        public array $children,
    ) {}
}

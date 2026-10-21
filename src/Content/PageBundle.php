<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

/**
 * A page bundle (`name/page.md` plus attachments).
 */
final readonly class PageBundle
{
    /**
     * @param  string  $directory  site-relative bundle directory
     * @param  string  $path  site-relative path of the bundle's `page.md`
     * @param  list<string>  $segments  NFC route segments of the bundle directory
     * @param  list<Attachment>  $attachments  sorted by relative path
     */
    public function __construct(
        public string $directory,
        public string $path,
        public array $segments,
        public array $attachments,
    ) {}
}

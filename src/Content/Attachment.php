<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

final readonly class Attachment
{
    /**
     * @param  string  $path  site-relative source path
     * @param  string  $relativePath  NFC path inside the bundle, `/`-separated
     */
    public function __construct(
        public string $path,
        public string $relativePath,
    ) {}
}

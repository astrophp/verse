<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

final readonly class PublicFile
{
    /**
     * @param  string  $path  site-relative source path, e.g. `public/favicon.ico`
     * @param  string  $outputPath  path inside the output directory, e.g. `favicon.ico`
     */
    public function __construct(
        public string $path,
        public string $outputPath,
    ) {}
}

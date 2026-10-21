<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

final readonly class BuildResult
{
    /**
     * @param  list<string>  $warnings
     * @param  int  $pageCount  HTML pages written (404.html included)
     */
    public function __construct(
        public array $warnings,
        public string $outputDir,
        public int $pageCount,
        public float $seconds,
    ) {}
}

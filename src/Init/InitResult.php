<?php

declare(strict_types=1);

namespace Astro\Verse\Init;

final readonly class InitResult
{
    /**
     * @param  list<string>  $files  site-relative paths of the created files, sorted
     * @param  bool  $createdDirectory  whether the target folder did not exist before
     */
    public function __construct(
        public string $directory,
        public array $files,
        public bool $createdDirectory,
    ) {}
}

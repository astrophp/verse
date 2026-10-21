<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

final readonly class DirEntry
{
    /**
     * @param  string  $name  the real name on disk
     * @param  string  $nfc  the name in Unicode NFC (used for routes and ordering)
     * @param  string  $absolute  absolute path
     * @param  string  $path  site-relative path (as shown in messages)
     */
    public function __construct(
        public string $name,
        public string $nfc,
        public string $absolute,
        public string $path,
        public bool $isDirectory,
    ) {}
}

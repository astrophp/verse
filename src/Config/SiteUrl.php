<?php

declare(strict_types=1);

namespace Astro\Verse\Config;

/**
 * The validated production URL: origin plus an optional directory base path.
 */
final readonly class SiteUrl
{
    /**
     * @param  string  $origin  scheme and authority, e.g. `https://example.com`
     * @param  string  $basePath  decoded NFC path starting and ending in `/`, e.g. `/notes/`
     */
    public function __construct(
        public string $origin,
        public string $basePath,
    ) {}

    public function host(): string
    {
        return (string) parse_url($this->origin, PHP_URL_HOST);
    }

    /**
     * Normalized form: always ends in `/`.
     */
    public function full(): string
    {
        return $this->origin.$this->basePath;
    }
}

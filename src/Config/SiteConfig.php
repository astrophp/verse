<?php

declare(strict_types=1);

namespace Astro\Verse\Config;

use Astro\Verse\Theme\ThemeSettings;
use DateTimeZone;

final readonly class SiteConfig
{
    /**
     * @param  list<SocialLink>  $socialLinks
     * @param  array<array-key, mixed>  $extra
     */
    public function __construct(
        public string $title,
        public ?string $tagline,
        public ?SiteUrl $url,
        public ?string $description,
        public ?Author $author,
        public array $socialLinks,
        public string $language,
        public string $timezone,
        public ThemeSettings $theme,
        public array $extra,
        public bool $sitemap = true,
        public bool $feed = true,
    ) {}

    public function basePath(): string
    {
        return $this->url === null ? '/' : $this->url->basePath;
    }

    public function dateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }
}

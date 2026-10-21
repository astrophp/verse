<?php

declare(strict_types=1);

namespace Astro\Verse\Theme;

/**
 * Resolved theme settings: the theme's defaults overridden, key by key, by `verse.yaml`.
 */
final readonly class ThemeSettings
{
    /**
     * @param  string  $name  name of the selected theme (`^[a-z0-9][a-z0-9-]*$`)
     * @param  ?string  $logo  site-root path (a public/ file) or absolute URL
     * @param  ?string  $accentLight  hex colour, null leaves the theme's own colour
     * @param  ?string  $accentDark  hex colour, null leaves the theme's own colour
     */
    public function __construct(
        public string $name = 'default',
        public ?string $logo = null,
        public ?string $accentLight = null,
        public ?string $accentDark = null,
        public bool $toc = true,
    ) {}
}

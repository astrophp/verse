<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration;

/**
 * Test double for the `$__verse` theme helper used by the standalone Blade test.
 */
final readonly class FixtureThemeContext
{
    public function __construct(public string $tag) {}

    public function assetTags(): string
    {
        return '<link rel="stylesheet" href="/assets/theme/app-'.$this->tag.'.css">'."\n"
            .'<script type="module" src="/assets/theme/app-'.$this->tag.'.js"></script>';
    }
}

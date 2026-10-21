<?php

declare(strict_types=1);

namespace Astro\Verse\Config;

final readonly class SocialLink
{
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
    ) {}
}

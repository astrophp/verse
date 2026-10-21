<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class Link
{
    public function __construct(
        public string $label,
        public string $url,
        public bool $current = false,
        public bool $external = false,
    ) {}
}

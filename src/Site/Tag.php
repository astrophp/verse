<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

final readonly class Tag
{
    public function __construct(
        public string $label,
        public string $slug,
        public string $route,
        public Listing $listing,
    ) {}

    public function count(): int
    {
        return $this->listing->itemCount();
    }
}

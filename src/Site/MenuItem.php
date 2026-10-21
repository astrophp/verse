<?php

declare(strict_types=1);

namespace Astro\Verse\Site;

final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $route,
        public ?int $order,
    ) {}
}

<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class TagRow
{
    public function __construct(
        public string $label,
        public string $url,
        public string $countLabel,
    ) {}
}

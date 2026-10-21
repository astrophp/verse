<?php

declare(strict_types=1);

namespace Astro\Verse\Config;

final readonly class Author
{
    public function __construct(
        public string $name,
        public ?string $url = null,
        public ?string $email = null,
    ) {}
}

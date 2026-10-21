<?php

declare(strict_types=1);

namespace Astro\Verse\Content;

use DateTimeImmutable;

final readonly class FrontMatter
{
    /**
     * @param  list<string>  $tags  NFC labels
     * @param  ?string  $slug  NFC
     * @param  ?string  $permalink  NFC, `/…/`
     * @param  array<array-key, mixed>  $extra
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?DateTimeImmutable $date = null,
        public ?DateTimeImmutable $updated = null,
        public bool $draft = false,
        public array $tags = [],
        public ?string $slug = null,
        public ?string $permalink = null,
        public ?string $canonical = null,
        public ?string $image = null,
        public ?string $externalUrl = null,
        public bool $menu = false,
        public ?int $order = null,
        public array $extra = [],
    ) {}

    public function isExternal(): bool
    {
        return $this->externalUrl !== null;
    }
}

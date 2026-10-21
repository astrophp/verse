<?php

declare(strict_types=1);

namespace Astro\Verse\View\Model;

final readonly class TagRef
{
    /**
     * @param  ?string  $url  the tag archive, null for a tag of a page, which has no archive
     * @param  bool  $current  the tag whose archive is being browsed
     */
    public function __construct(
        public string $label,
        public ?string $url,
        public bool $current = false,
    ) {}
}
